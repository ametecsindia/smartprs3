package com.ametecs.smartprs3.employee;

import android.Manifest;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ContentValues;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.StateListDrawable;
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
import android.util.Base64;
import android.view.Gravity;
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
import android.widget.ImageView;
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
    /** Where Explore Demo opens when no company address is typed. */
    private static final String DEMO_ADDRESS = "https://app.smartprs.com";
    /** A page to open instead of the dashboard (Explore Demo); used once. */
    private String startPath = null;
    private static final int REQ_LOCATION = 1;
    private static final int REQ_FILE = 2;
    private static final int REQ_CAMERA = 3;
    private static final long POLL_MS = 15000;
    // THE SMARTPRS LOOK (owner, 12 Nov 2026, v1.4: "SmartPRS logo … professional … SmartPRS theme") — the website's colours.
    private static final String NAVY = "#0A1628";
    private static final String NAVY2 = "#102744";
    private static final String ORANGE = "#F97316";
    private static final String ORANGE2 = "#EA580C";
    private static final String INK = "#0F1D33";
    private static final String MUTED = "#516074";
    private static final String LINE = "#E2E8F0";
    /** public/images/smartprs-logo.png (white lettering, for the navy band), carried inside the app so it shows offline. */
    private static final String LOGO_PNG = "iVBORw0KGgoAAAANSUhEUgAAARQAAABRCAYAAAATklkSAAAWfmNhQlgAABZ+anVtYgAAAB5qdW1kYzJwYQARABCAAACqADibcQNjMnBhAAAAFlhqdW1iAAAAR2p1bWRjMm1hABEAEIAAAKoAOJtxA3VybjpjMnBhOjU4NjQ2OGZiLWQ1ZGUtNDFkNC05YTVlLWRhOTcxMjhkMzc4MgAAAAOTanVtYgAAAClqdW1kYzJhcwARABCAAACqADibcQNjMnBhLmFzc2VydGlvbnMAAAAAuGp1bWIAAABEanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5pbmdyZWRpZW50LnYzAAAAABhjMnNo6R54UbohyfbU7wrrzu+mqAAAAGxjYm9yo2lkYzpmb3JtYXRpaW1hZ2UvcG5namluc3RhbmNlSUR4LHhtcDppaWQ6NjkwNjA5OTEtYzU1Mi00NmU0LTk1MmYtNTNiYzA1ODY1OWNhbHJlbGF0aW9uc2hpcGhwYXJlbnRPZgAAAeJqdW1iAAAAQWp1bWRjYm9yABEAEIAAAKoAOJtxE2MycGEuYWN0aW9ucy52MgAAAAAYYzJzaMsQRm3W94N5iZTkPPBYPxgAAAGZY2JvcqJnYWN0aW9uc4KiZmFjdGlvbmtjMnBhLm9wZW5lZGpwYXJhbWV0ZXJzoWtpbmdyZWRpZW50c4GiY3VybHgtc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5pbmdyZWRpZW50LnYzZGhhc2hYILG0SKDzRzzkrLcqyl4iOkunf5v0QgEB7In/1NPjS/lLpGZhY3Rpb254HWNvbS5hbnRocm9waWMuY2xhdWRlLnByb3ZpZGVkanBhcmFtZXRlcnOheB9jb20uYW50aHJvcGljLm9yaWdpbi1jb25maWRlbmNlZ3Vua25vd25rZGVzY3JpcHRpb254ZkNsYXVkZSBwcm92aWRlZCB0aGlzIGZpbGUgYXQgdGhlIHJlcXVlc3Qgb2YgYSB1c2VyIGFuZCBtYXkgaGF2ZSBjcmVhdGVkIG9yIG1vZGlmaWVkIHRoZSBmaWxlIGNvbnRlbnRzLm1zb2Z0d2FyZUFnZW50oWRuYW1lZkNsYXVkZXJhbGxBY3Rpb25zSW5jbHVkZWT1AAAAyGp1bWIAAABAanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5oYXNoLmRhdGEAAAAAGGMyc2g+GYallCSHul53zhkMWV67AAAAgGNib3KlY2FsZ2ZzaGEyNTZjcGFkTQAAAAAAAAAAAAAAAABkaGFzaFggYw9O0HPFWLbkCxBa2y46wGQnySUJn0mMWe3YBOqTHDNkbmFtZW5qdW1iZiBtYW5pZmVzdGpleGNsdXNpb25zgaJlc3RhcnQYIWZsZW5ndGgZFooAAAI+anVtYgAAACdqdW1kYzJjbAARABCAAACqADibcQNjMnBhLmNsYWltLnYyAAAAAg9jYm9ypWNhbGdmc2hhMjU2aXNpZ25hdHVyZXhNc2VsZiNqdW1iZj0vYzJwYS91cm46YzJwYTo1ODY0NjhmYi1kNWRlLTQxZDQtOWE1ZS1kYTk3MTI4ZDM3ODIvYzJwYS5zaWduYXR1cmVqaW5zdGFuY2VJRHgseG1wOmlpZDpmYTA3ZmJlNy01ZTYwLTQ4MmQtODY4YS05NTdiYmFlNmY4MzlyY3JlYXRlZF9hc3NlcnRpb25zg6JjdXJseC1zZWxmI2p1bWJmPWMycGEuYXNzZXJ0aW9ucy9jMnBhLmluZ3JlZGllbnQudjNkaGFzaFggsbRIoPNHPOSstyrKXiI6S6d/m/RCAQHsif/U0+NL+UuiY3VybHgqc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5hY3Rpb25zLnYyZGhhc2hYIHyMcPgt7wMEdsktJuvQM1JmGYP11WRnyjoZ3FkwHWftomN1cmx4KXNlbGYjanVtYmY9YzJwYS5hc3NlcnRpb25zL2MycGEuaGFzaC5kYXRhZGhhc2hYIDwNN63qBHyvrBLEHpxl2GY4rKV0xf/z89/ohELnk4ckdGNsYWltX2dlbmVyYXRvcl9pbmZvo2RuYW1lb0FudGhyb3BpYyBGaWxlc2d2ZXJzaW9uZTEuMC4wa3NwZWNWZXJzaW9uZTIuNC4wAAAQOGp1bWIAAAAoanVtZGMyY3MAEQAQgAAAqgA4m3EDYzJwYS5zaWduYXR1cmUAAAAQCGNib3LShFkCEqIBJhghWQIKMIICBjCCAY2gAwIBAgIUQOWgCu7COdC+uIP6BkIFPWdVEwAwCgYIKoZIzj0EAwMwSTEXMBUGA1UEChMOQW50aHJvcGljLCBQQkMxLjAsBgNVBAMTJUFudGhyb3BpYyBDb250ZW50IENyZWRlbnRpYWxzIFJvb3QgQ0EwHhcNMjYwODA3MTg0MzU2WhcNMjgwODA2MTk0MzU2WjBEMRcwFQYDVQQKEw5BbnRocm9waWMsIFBCQzEpMCcGA1UEAxMgQW50aHJvcGljIENsYXVkZSBDb250ZW50IFNpZ25pbmcwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAASYegpry1AYBRTVNL1CpTlbROnY3dey+UrsF9C3phYrATN3ZHf93Mo8RQN0KOUuOn19P4oWNFWe5n2/She9N7eTo1gwVjAOBgNVHQ8BAf8EBAMCB4AwFQYDVR0lBA4wDAYKKwYBBAGD6F4CATAMBgNVHRMBAf8EAjAAMB8GA1UdIwQYMBaAFM5R4gSBTmRbI/jjxM+aPpzB11zCMAoGCCqGSM49BAMDA2cAMGQCMDFzHRSeAXrSy1WOzkbhPZ6Km2wGTmZ/2gK18k8BQGXyqz88Rdrz6CTX9flAnYNVxgIwcF9c3fVhqmJKpi+UhasNUMko69cyX6STPfta3Q8EjyzDjzoyrol46FP6VFHhvUcJoWNwYWRZDZ4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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";

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

        if (Build.VERSION.SDK_INT >= 21) {
            getWindow().setStatusBarColor(Color.parseColor(NAVY));
        }

        ScrollView sv = new ScrollView(this);
        sv.setFillViewport(true);
        sv.setBackgroundColor(Color.parseColor("#F4F6FA"));
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        sv.addView(root);

        // Navy band with the SmartPRS logo.
        LinearLayout band = new LinearLayout(this);
        band.setOrientation(LinearLayout.VERTICAL);
        band.setGravity(Gravity.CENTER_HORIZONTAL);
        band.setPadding(dp(24), dp(44), dp(24), dp(70));
        band.setBackground(new GradientDrawable(GradientDrawable.Orientation.TL_BR, new int[]{Color.parseColor(NAVY2), Color.parseColor(NAVY)}));
        ImageView logo = new ImageView(this);
        byte[] png = Base64.decode(LOGO_PNG, Base64.DEFAULT);
        Bitmap bm = BitmapFactory.decodeByteArray(png, 0, png.length);
        logo.setImageBitmap(bm);
        logo.setAdjustViewBounds(true);
        logo.setContentDescription("SmartPRS");
        band.addView(logo, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, dp(56)));
        TextView tag = new TextView(this);
        tag.setText("HR · Attendance · Payroll");
        tag.setTextColor(Color.parseColor("#B8C4D4"));
        tag.setTextSize(13);
        tag.setGravity(Gravity.CENTER);
        tag.setPadding(0, dp(10), 0, 0);
        band.addView(tag);
        root.addView(band);

        // White card lifted over the band.
        LinearLayout l = new LinearLayout(this);
        l.setOrientation(LinearLayout.VERTICAL);
        l.setPadding(dp(22), dp(24), dp(22), dp(24));
        l.setBackground(rounded("#FFFFFF", 20, null, 0));
        l.setElevation(dp(6));
        LinearLayout.LayoutParams cp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        cp.setMargins(dp(16), -dp(44), dp(16), dp(16));
        root.addView(l, cp);

        TextView h = new TextView(this);
        h.setText(title);
        h.setTextSize(22);
        h.setTypeface(Typeface.DEFAULT_BOLD);
        h.setTextColor(Color.parseColor(INK));
        l.addView(h);

        if (text != null) {
            TextView t = new TextView(this);
            t.setText(text);
            t.setTextSize(14);
            t.setTextColor(Color.parseColor(MUTED));
            t.setLineSpacing(0, 1.2f);
            t.setPadding(0, dp(6), 0, dp(4));
            l.addView(t);
        }

        // Pushes the footer to the bottom on tall phones.
        View gap = new View(this);
        root.addView(gap, new LinearLayout.LayoutParams(1, 0, 1f));
        TextView foot = new TextView(this);
        foot.setText("Powered by Ametecs India");
        foot.setTextSize(12);
        foot.setTextColor(Color.parseColor("#94A3B8"));
        foot.setGravity(Gravity.CENTER);
        foot.setPadding(0, dp(8), 0, dp(20));
        root.addView(foot);

        closeWeb();
        setContentView(sv);

        return l;
    }

    /** A rounded box; stroke may be null. */
    private GradientDrawable rounded(String fill, int radiusDp, String stroke, int strokeDp) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(Color.parseColor(fill));
        g.setCornerRadius(dp(radiusDp));

        if (stroke != null) {
            g.setStroke(dp(strokeDp), Color.parseColor(stroke));
        }

        return g;
    }

    private EditText field(LinearLayout l, String label, String hint, String value, int type) {
        TextView t = new TextView(this);
        t.setText(label);
        t.setTextSize(13);
        t.setTypeface(Typeface.DEFAULT_BOLD);
        t.setTextColor(Color.parseColor(INK));
        t.setPadding(0, dp(16), 0, dp(6));
        l.addView(t);
        EditText e = new EditText(this);
        e.setHint(hint);
        e.setText(value);
        e.setSingleLine(true);
        e.setInputType(type);
        e.setTextSize(15);
        e.setTextColor(Color.parseColor(INK));
        e.setHintTextColor(Color.parseColor("#94A3B8"));
        e.setPadding(dp(14), dp(12), dp(14), dp(12));
        StateListDrawable box = new StateListDrawable();
        box.addState(new int[]{android.R.attr.state_focused}, rounded("#FFFFFF", 12, ORANGE, 2));
        box.addState(new int[]{}, rounded("#F8FAFC", 12, LINE, 1));
        e.setBackground(box);
        e.setMinHeight(dp(50));
        l.addView(e, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT));

        return e;
    }

    private void button(LinearLayout l, String label, boolean primary, View.OnClickListener click) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextSize(16);
        b.setTypeface(Typeface.DEFAULT_BOLD);
        b.setStateListAnimator(null);

        if (primary) {
            GradientDrawable g = new GradientDrawable(GradientDrawable.Orientation.LEFT_RIGHT, new int[]{Color.parseColor(ORANGE), Color.parseColor(ORANGE2)});
            g.setCornerRadius(dp(12));
            b.setBackground(g);
            b.setTextColor(Color.WHITE);
        } else {
            b.setBackground(rounded("#FFFFFF", 12, ORANGE, 1));
            b.setTextColor(Color.parseColor(ORANGE));
        }

        LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(52));
        lp.topMargin = dp(primary ? 22 : 12);
        l.addView(b, lp);
        b.setOnClickListener(click);
    }

    private void problem(LinearLayout l, String problem) {
        if (problem != null) {
            TextView t = new TextView(this);
            t.setText(problem);
            t.setTextSize(14);
            t.setTextColor(Color.parseColor("#991B1B"));
            t.setBackground(rounded("#FEF2F2", 10, "#FECACA", 1));
            t.setPadding(dp(12), dp(10), dp(12), dp(10));
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
            lp.topMargin = dp(12);
            l.addView(t, lp);
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

        // EXPLORE DEMO (owner, 12 Nov 2026): the visitor's own demo company — form, passkey, straight in (public/demo.php).
        button(l, "Explore Demo", false, new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                String a = addr.getText().toString().trim();
                String b = parseAddress(a.isEmpty() ? DEMO_ADDRESS : a)[0];
                prefs.edit().putString("base", b).apply();
                startPath = "/demo.php";
                closeWeb();
                openWeb(null);
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
        // PINCH TO ZOOM IN / OUT ON EVERY SCREEN (owner, 12 Nov 2026), without the old +/- buttons on top of the page.
        s.setSupportZoom(true);
        s.setBuiltInZoomControls(true);
        s.setDisplayZoomControls(false);
        s.setUserAgentString(s.getUserAgentString() + " SmartPRS3-Mobile/1.5");

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
        String path = startPath;
        startPath = null;
        web.loadUrl(path != null ? base() + path : base() + "/app.php?r=dashboard&app=1&device=" + Uri.encode(deviceId)
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
