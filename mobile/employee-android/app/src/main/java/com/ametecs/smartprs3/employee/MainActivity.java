package com.ametecs.smartprs3.employee;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ContentValues;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.MediaStore;
import android.provider.Settings;
import android.text.InputType;
import android.view.View;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.PermissionRequest;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.UUID;

/**
 * SMARTPRS3 MOBILE APP (owner, 5 Nov 2026; one app for everybody since 6 Nov — menus, actions and data follow
 * whoever signs in, exactly as on the web; a login with no employee record registers with its email).
 *
 * SIGN-IN LIKE SMARTEPT (owner, 12 Nov 2026, v1.2): the app opens on a sign-in screen — company address, login
 * (Employee ID or SmartPRS email) and password — checked at /api/mobile/signin. A phone already registered to that
 * login signs straight in: the server hands back a one-time login link and SmartPRS opens with that profile's menus.
 * Any other phone is asked once for the ACTIVATION CODE the administrator issued (/api/mobile/activate); then it is
 * approved at once or waits for the Admin (Administration → Mobile Devices), as the company's phone approval says.
 * Every page request carries the cookie sprs_app_device and the server (surface/partials/boot-hooks.php) signs the
 * person out the moment the phone is not approved or belongs to another login. Signing out of SmartPRS, or a session
 * that ended, comes back to this sign-in screen; the phone stays registered, so no code is asked again.
 */
public class MainActivity extends Activity {

    private static final String COOKIE = "sprs_app_device";
    private static final int REQ_LOCATION = 1;
    private static final int REQ_FILE = 2;
    private static final int REQ_CAMERA = 3;
    private static final long POLL_MS = 15000;
    private static final String NAVY = "#1F3A5F";

    private final Handler ui = new Handler(Looper.getMainLooper());
    private SharedPreferences prefs;
    private String deviceId;
    private WebView web;
    private ValueCallback<Uri[]> fileCallback;
    private Uri selfieUri;
    private GeolocationPermissions.Callback geoCallback;
    private String geoOrigin;
    private PermissionRequest camRequest;
    private boolean waiting;
    private boolean checking;
    private String password = ""; // kept in memory only, so an approval while waiting signs in without retyping it

    private final Runnable poll = new Runnable() {
        @Override
        public void run() {
            if (waiting) {
                checkStatus();
            }
        }
    };

    private interface Done {
        void done(JSONObject res);
    }

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        prefs = getSharedPreferences("smartprs", MODE_PRIVATE);
        deviceId = prefs.getString("device_id", "");

        if (deviceId.isEmpty()) {
            String a = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
            deviceId = (a == null || a.isEmpty()) ? UUID.randomUUID().toString().replace("-", "") : a;
            prefs.edit().putString("device_id", deviceId).apply();
        }

        if (!base().isEmpty() && prefs.getBoolean("approved", false)) {
            openWeb(null); // the web session may still be open; if it ended, its sign-in page brings back showSignIn()
            checkStatus();
        } else {
            showSignIn(null);
        }
    }

    @Override
    protected void onResume() {
        super.onResume();

        // A phone revoked while the app was in the background stops here, before anything shows.
        if (web != null) {
            checkStatus();
        }
    }

    @Override
    protected void onDestroy() {
        ui.removeCallbacks(poll);
        closeWeb();
        super.onDestroy();
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) {
            web.goBack();
        } else {
            super.onBackPressed();
        }
    }

    // ------------------------------------------------------------------ server

    private String base() {
        return prefs.getString("base", "");
    }

    private String slug() {
        return prefs.getString("slug", "");
    }

    /** "https://app.smartprs.com/c/acme" → {"https://app.smartprs.com", "acme"}; a bare domain has no slug. */
    static String[] parseAddress(String raw) {
        String u = raw.trim();

        if (!u.matches("(?i)^https?://.*")) {
            u = "https://" + u;
        }

        u = u.replaceAll("[?#].*$", "").replaceAll("/+$", "");
        String slug = "";
        int i = u.toLowerCase().lastIndexOf("/c/");

        if (i > 0) {
            slug = u.substring(i + 3).replaceAll("[^A-Za-z0-9].*$", "");
            u = u.substring(0, i);
        }

        u = u.replaceAll("(?i)/(app\\.php|login)$", "").replaceAll("/+$", "");

        return new String[]{u, slug};
    }

    private void call(final String op, final JSONObject body, final Done done) {
        final String url = base() + "/api/mobile/" + op;

        new Thread(new Runnable() {
            @Override
            public void run() {
                JSONObject out = null;

                try {
                    HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
                    c.setConnectTimeout(15000);
                    c.setReadTimeout(20000);
                    c.setRequestMethod("POST");
                    c.setDoOutput(true);
                    c.setRequestProperty("Content-Type", "application/json");
                    c.setRequestProperty("Accept", "application/json");
                    OutputStream os = c.getOutputStream();
                    os.write(body.toString().getBytes(StandardCharsets.UTF_8));
                    os.close();
                    int code = c.getResponseCode();
                    InputStream in = code >= 400 ? c.getErrorStream() : c.getInputStream();
                    String text = read(in).trim();
                    out = new JSONObject(text.isEmpty() ? "{}" : text);
                    out.put("_code", code);
                } catch (Exception e) {
                    out = null; // unreachable, or not a SmartPRS address: the caller says so
                }

                final JSONObject res = out;
                ui.post(new Runnable() {
                    @Override
                    public void run() {
                        done.done(res);
                    }
                });
            }
        }).start();
    }

    private static String read(InputStream in) throws java.io.IOException {
        if (in == null) {
            return "";
        }

        ByteArrayOutputStream b = new ByteArrayOutputStream();
        byte[] buf = new byte[4096];

        for (int n; (n = in.read(buf)) > 0; ) {
            b.write(buf, 0, n);
        }

        in.close();

        return b.toString("UTF-8");
    }

    private JSONObject credentials() {
        JSONObject body = new JSONObject();

        try {
            body.put("device_id", deviceId);
            body.put("workspace", slug());
            body.put("login", prefs.getString("login", ""));
            body.put("password", password);
            body.put("device_name", (Build.MANUFACTURER + " " + Build.MODEL).trim());
            body.put("platform", "Android");
        } catch (Exception ignored) {
            // JSONObject.put only throws for a null key or a NaN number; neither happens here
        }

        return body;
    }

    /** signin or activate: both answer the same way. */
    private void signIn(final String op, final String activationCode) {
        JSONObject body = credentials();

        try {
            body.put("activation_code", activationCode);
        } catch (Exception ignored) {
            // see credentials()
        }

        page(op.equals("activate") ? "Activating this phone…" : "Signing in…", "One moment.");
        call(op, body, new Done() {
            @Override
            public void done(JSONObject res) {
                if (res == null) {
                    showSignIn("Could not reach SmartPRS at " + base() + ". Check the company address and your internet connection.");
                } else if (!res.optBoolean("ok", false)) {
                    String why = res.optString("error", "Sign-in failed.");
                    if (op.equals("activate")) {
                        showActivate(res.optString("name", prefs.getString("name", "")), why);
                    } else {
                        showSignIn(why);
                    }
                } else {
                    prefs.edit().putString("name", res.optString("name", "")).apply();
                    String status = res.optString("status", "");

                    if (!res.optBoolean("registered", false)) {
                        showActivate(res.optString("name", ""), null);
                    } else if (status.equals("approved")) {
                        password = "";
                        prefs.edit().putBoolean("approved", true).apply();
                        closeWeb();
                        openWeb(res.optString("login", ""));
                    } else {
                        prefs.edit().putString("code", res.optString("code", "")).putBoolean("approved", false).apply();
                        showWaiting();
                    }
                }
            }
        });
    }

    private void checkStatus() {
        if (checking) {
            return;
        }

        checking = true;
        JSONObject body = new JSONObject();

        try {
            body.put("device_id", deviceId);
            body.put("workspace", slug());
        } catch (Exception ignored) {
            // see credentials()
        }

        call("status", body, new Done() {
            @Override
            public void done(JSONObject res) {
                checking = false;
                String status = res == null ? "" : res.optString("status", "");

                if (res == null) {
                    if (web == null && waiting) {
                        ui.postDelayed(poll, POLL_MS); // offline while waiting: keep trying
                    }
                } else if (status.equals("approved")) {
                    if (web == null && waiting) {
                        // Approved while waiting: sign in now (the password is still in memory), else ask for it.
                        if (password.isEmpty()) {
                            showSignIn("This phone is approved. Sign in.");
                        } else {
                            signIn("signin", "");
                        }
                    }
                } else if (status.equals("pending")) {
                    if (!waiting) {
                        lockOut();
                        showWaiting();
                    } else {
                        ui.postDelayed(poll, POLL_MS);
                    }
                } else if (status.equals("rejected") || status.equals("revoked")) {
                    lockOut();
                    String why = res.optString("reason", "");
                    showSignIn(why.isEmpty() || why.equals("null") ? "Your administrator has not allowed this phone." : why);
                } else if (web != null) {
                    // The registration was removed on the server: sign in again (it asks for an activation code).
                    lockOut();
                    showSignIn(null);
                }
            }
        });
    }

    /** Forget the approval and the web session on this phone. */
    private void lockOut() {
        prefs.edit().putBoolean("approved", false).apply();
        closeWeb();
        CookieManager.getInstance().removeAllCookies(null);
    }

    // ------------------------------------------------------------------ screens

    private LinearLayout page(String title, String text) {
        waiting = false;
        ui.removeCallbacks(poll);
        ScrollView sv = new ScrollView(this);
        LinearLayout l = new LinearLayout(this);
        l.setOrientation(LinearLayout.VERTICAL);
        int p = dp(24);
        l.setPadding(p, dp(40), p, p);
        sv.addView(l);

        TextView brand = new TextView(this);
        brand.setText("SmartPRS3");
        brand.setTextColor(Color.parseColor("#B8893B"));
        brand.setTypeface(Typeface.DEFAULT_BOLD);
        l.addView(brand);

        TextView h = new TextView(this);
        h.setText(title);
        h.setTextSize(22);
        h.setTypeface(Typeface.DEFAULT_BOLD);
        h.setTextColor(Color.parseColor(NAVY));
        h.setPadding(0, dp(8), 0, dp(8));
        l.addView(h);

        if (text != null) {
            TextView t = new TextView(this);
            t.setText(text);
            t.setTextSize(15);
            t.setPadding(0, 0, 0, dp(12));
            l.addView(t);
        }

        closeWeb();
        setContentView(sv);

        return l;
    }

    private EditText field(LinearLayout l, String label, String hint, String value, int type) {
        TextView t = new TextView(this);
        t.setText(label);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        t.setPadding(0, dp(12), 0, dp(4));
        l.addView(t);
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setText(value);
        e.setSingleLine(true);
        e.setInputType(type);
        l.addView(e);

        return e;
    }

    private void button(LinearLayout l, String label, boolean primary, View.OnClickListener click) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);

        if (primary) {
            b.setBackgroundColor(Color.parseColor(NAVY));
            b.setTextColor(Color.WHITE);
        }

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        lp.topMargin = dp(14);
        l.addView(b, lp);
        b.setOnClickListener(click);
    }

    private void problem(LinearLayout l, String problem) {
        if (problem != null) {
            TextView t = new TextView(this);
            t.setText(problem);
            t.setTextColor(Color.parseColor("#991B1B"));
            t.setBackgroundColor(Color.parseColor("#FEE2E2"));
            t.setPadding(dp(12), dp(10), dp(12), dp(10));
            l.addView(t);
        }
    }

    private void showSignIn(String problem) {
        LinearLayout l = page("Sign in", null);
        problem(l, problem);
        final EditText addr = field(l, "Company address", "https://app.smartprs.com/c/yourcompany",
                prefs.getString("address", ""), InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        final EditText login = field(l, "Employee ID or email", "e.g. EMP001 or you@company.com", prefs.getString("login", ""),
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);
        final EditText pw = field(l, "Password", "", "", InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);

        button(l, "Sign in", true, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                String a = addr.getText().toString().trim();
                String e = login.getText().toString().trim();
                String p = pw.getText().toString();

                if (a.isEmpty() || e.isEmpty() || p.isEmpty()) {
                    Toast.makeText(MainActivity.this, "Enter the company address, your Employee ID or email, and your password.", Toast.LENGTH_LONG).show();
                    return;
                }

                String[] parsed = parseAddress(a);
                prefs.edit().putString("address", a).putString("base", parsed[0]).putString("slug", parsed[1]).putString("login", e).apply();
                password = p;
                signIn("signin", "");
            }
        });
    }

    /** First sign-in on this phone: the activation code from the administrator. */
    private void showActivate(String name, String problem) {
        LinearLayout l = page("Activate this phone", (name == null || name.isEmpty() ? "" : "Hello " + name + ". ")
                + "Enter the activation code your administrator sent you. You need it only once on this phone.");
        problem(l, problem);
        final EditText code = field(l, "Activation code", "e.g. ABCD2345", "",
                InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_FLAG_CAP_CHARACTERS | InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS);

        button(l, "Activate", true, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                String c = code.getText().toString().trim();

                if (c.isEmpty()) {
                    Toast.makeText(MainActivity.this, "Enter the activation code.", Toast.LENGTH_LONG).show();
                    return;
                }

                signIn("activate", c);
            }
        });
        backButton(l);
    }

    private void showWaiting() {
        String code = prefs.getString("code", "");
        LinearLayout l = page("Waiting for approval", "Your administrator approves this phone"
                + (code.isEmpty() ? "" : " (phone code " + code + ")") + ". SmartPRS opens by itself once it is approved.");
        button(l, "Check again", true, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                checkStatus();
            }
        });
        backButton(l);
        waiting = true;
        ui.postDelayed(poll, POLL_MS);
    }

    private void backButton(LinearLayout l) {
        button(l, "Back to sign in", false, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                password = "";
                showSignIn(null);
            }
        });
    }

    // ------------------------------------------------------------------ SmartPRS in a web view

    private void openWeb(final String loginLink) {
        if (web != null) {
            return;
        }

        waiting = false;
        ui.removeCallbacks(poll);
        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setCookie(base(), COOKIE + "=" + deviceId + "; path=/");
        cm.flush();

        web = new WebView(this);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setGeolocationEnabled(true);
        s.setUserAgentString(s.getUserAgentString() + " SmartPRS3-Mobile/1.2");

        final String host = Uri.parse(base()).getHost();
        web.setWebViewClient(new WebViewClient() {
            // SIGNED OUT, OR THE SESSION ENDED: SmartPRS's own sign-in page is replaced by the app's sign-in screen.
            @Override
            public void onPageFinished(WebView v, String url) {
                if (web != null && url != null && url.contains("r=signin")) {
                    ui.post(new Runnable() {
                        @Override
                        public void run() {
                            showSignIn(null);
                        }
                    });
                }
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView v, WebResourceRequest r) {
                Uri u = r.getUrl();

                if (host != null && host.equalsIgnoreCase(u.getHost())) {
                    return false; // SmartPRS pages stay in the app
                }

                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, u)); // maps, mail, phone, other sites
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "No app on this phone can open that link.", Toast.LENGTH_SHORT).show();
                }

                return true;
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback cb) {
                if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
                    cb.invoke(origin, true, false);
                } else {
                    geoOrigin = origin;
                    geoCallback = cb;
                    requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, REQ_LOCATION);
                }
            }

            // QR ATTENDANCE (owner, 9 Nov 2026): Punch → Scan QR opens the camera inside the page (getUserMedia).
            @Override
            public void onPermissionRequest(PermissionRequest req) {
                boolean video = false;
                for (String r : req.getResources()) {
                    video = video || PermissionRequest.RESOURCE_VIDEO_CAPTURE.equals(r);
                }
                if (!video) {
                    req.deny();
                } else if (checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {
                    req.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
                } else {
                    camRequest = req;
                    requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAMERA);
                }
            }

            @Override
            public boolean onShowFileChooser(WebView v, ValueCallback<Uri[]> cb, FileChooserParams params) {
                if (fileCallback != null) {
                    fileCallback.onReceiveValue(null);
                }

                fileCallback = cb;

                try {
                    // SELFIE OR FILE (owner, 6 Nov 2026): for a photo the chooser also offers the front camera.
                    Intent pick = params.createIntent();
                    Intent chooser = pick;
                    String[] types = params.getAcceptTypes();
                    boolean image = false;
                    for (String t : types == null ? new String[0] : types) {
                        // A photo box may list extensions (".jpg,.jpeg,.png,.webp") instead of image/* (6 Nov 2026).
                        String tl = t == null ? "" : t.toLowerCase();
                        image = image || tl.isEmpty() || tl.startsWith("image") || tl.contains(".jpg") || tl.contains(".jpeg")
                                || tl.contains(".png") || tl.contains(".webp") || tl.contains(".gif") || tl.contains(".heic");
                    }
                    selfieUri = null;
                    if (image && checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
                        // The app declares CAMERA (QR scan, 9 Nov 2026), so Android refuses the camera app until it is allowed.
                        fileCallback.onReceiveValue(null);
                        fileCallback = null;
                        requestPermissions(new String[]{Manifest.permission.CAMERA}, REQ_CAMERA);
                        Toast.makeText(MainActivity.this, "Allow the camera, then tap the photo button again.", Toast.LENGTH_LONG).show();
                        return true;
                    }
                    Intent cam = new Intent(MediaStore.ACTION_IMAGE_CAPTURE);
                    if (image && cam.resolveActivity(getPackageManager()) != null) {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                            ContentValues cv = new ContentValues();
                            cv.put(MediaStore.Images.Media.DISPLAY_NAME, "selfie-" + System.currentTimeMillis() + ".jpg");
                            cv.put(MediaStore.Images.Media.MIME_TYPE, "image/jpeg");
                            cv.put(MediaStore.Images.Media.RELATIVE_PATH, Environment.DIRECTORY_PICTURES + "/SmartPRS");
                            selfieUri = getContentResolver().insert(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, cv);
                            if (selfieUri != null) {
                                cam.putExtra(MediaStore.EXTRA_OUTPUT, selfieUri);
                                cam.addFlags(Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
                            }
                        }
                        // ponytail: front-camera hints; camera apps that ignore them open the rear camera (user can flip).
                        cam.putExtra("android.intent.extras.CAMERA_FACING", 1);
                        cam.putExtra("android.intent.extras.LENS_FACING_FRONT", 1);
                        cam.putExtra("android.intent.extra.USE_FRONT_CAMERA", true);
                        if (params.isCaptureEnabled()) {
                            // TAKE SELFIE button (capture="user", 6 Nov 2026): straight to the camera, no chooser.
                            chooser = cam;
                        } else {
                            chooser = Intent.createChooser(pick, "Photo");
                            chooser.putExtra(Intent.EXTRA_INITIAL_INTENTS, new Intent[]{cam});
                        }
                    }
                    startActivityForResult(chooser, REQ_FILE);
                } catch (Exception e) {
                    fileCallback = null;
                    return false;
                }

                return true;
            }
        });

        web.setDownloadListener((url, userAgent, disposition, mime, length) -> {
            try {
                String name = URLUtil.guessFileName(url, disposition, mime);
                DownloadManager.Request q = new DownloadManager.Request(Uri.parse(url));
                q.addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url));
                q.addRequestHeader("User-Agent", userAgent);
                q.setTitle(name);
                q.setMimeType(mime);
                q.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                q.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
                ((DownloadManager) getSystemService(DOWNLOAD_SERVICE)).enqueue(q);
                Toast.makeText(this, "Downloading " + name, Toast.LENGTH_SHORT).show();
            } catch (Exception e) {
                Toast.makeText(this, "The file could not be downloaded.", Toast.LENGTH_LONG).show();
            }
        });

        setContentView(web);
        // The one-time login link (from /api/mobile/signin) opens the session as that login; boot-hooks spends it.
        web.loadUrl(base() + "/app.php?r=dashboard&app=1&device=" + Uri.encode(deviceId)
                + (loginLink == null || loginLink.isEmpty() ? "" : "&applogin=" + Uri.encode(loginLink)));
    }

    private void closeWeb() {
        if (web != null) {
            WebView w = web;
            web = null;
            w.stopLoading();
            w.destroy();
        }
    }

    @Override
    public void onRequestPermissionsResult(int req, String[] perms, int[] results) {
        if (req == REQ_CAMERA && camRequest != null) {
            boolean ok = results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED;
            if (ok) {
                camRequest.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});
            } else {
                camRequest.deny();
            }
            camRequest = null;
            return;
        }
        if (req == REQ_LOCATION && geoCallback != null) {
            boolean granted = false;

            for (int r : results) {
                granted = granted || r == PackageManager.PERMISSION_GRANTED;
            }

            geoCallback.invoke(geoOrigin, granted, false);
            geoCallback = null;
        }
    }

    @Override
    protected void onActivityResult(int req, int result, Intent data) {
        if (req == REQ_FILE && fileCallback != null) {
            Uri[] picked = WebChromeClient.FileChooserParams.parseResult(result, data);
            if (result == RESULT_OK && (picked == null || picked.length == 0)) {
                // The camera was used: the full photo is in selfieUri (Android 10+), else the camera's own thumbnail.
                if (selfieUri != null) {
                    picked = new Uri[]{selfieUri};
                } else if (data != null && data.getExtras() != null && data.getExtras().get("data") instanceof android.graphics.Bitmap) {
                    try {
                        java.io.File f = new java.io.File(getCacheDir(), "selfie-" + System.currentTimeMillis() + ".jpg");
                        try (java.io.FileOutputStream o = new java.io.FileOutputStream(f)) {
                            ((android.graphics.Bitmap) data.getExtras().get("data")).compress(android.graphics.Bitmap.CompressFormat.JPEG, 92, o);
                        }
                        picked = new Uri[]{Uri.fromFile(f)};
                    } catch (Exception e) {
                        picked = null;
                    }
                }
            } else if (selfieUri != null) {
                try { getContentResolver().delete(selfieUri, null, null); } catch (Exception ignored) { }
            }
            selfieUri = null;
            fileCallback.onReceiveValue(picked);
            fileCallback = null;
        } else {
            super.onActivityResult(req, result, data);
        }
    }

    private int dp(int v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }
}
