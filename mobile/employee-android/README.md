# SmartPRS3 Mobile app — Android

One app for everybody: after sign-in it shows exactly what that login has on the web — an employee sees
their own screens and data; an Admin / HR login sees its menus, approvals, edit and delete options. Package `com.ametecs.smartprs3.employee` (the SmartPRS2 app,
`com.ametecs.smartprs`, is separate and unchanged).

## How it works

1. **First run.** The employee enters the company address — e.g. `https://app.smartprs.com/c/yourcompany`
   (or the company's own domain) — and their **Employee ID** (or, for a login with no employee record, their **SmartPRS login email**), then taps *Register this phone*.
2. **Waiting for approval.** The phone appears as *Pending* in **Administration → Mobile Devices**
   with the employee's code and the phone model. The app checks every 15 seconds.
3. **Admin approves.** The app opens SmartPRS and the employee signs in with their own SmartPRS
   email and password. From then on the app opens straight to SmartPRS on this phone.
4. **Revoked / rejected.** The phone is locked out: the app shows the reason, and the server signs
   out any session from that phone on its very next page, even if the app is still open. Another
   employee's login on this phone is refused too.

Inside the app: a Home screen of icon tiles for every menu item this login may open, a bottom bar
(Home · Punch · Leave · Alerts · Profile), and **Punch In / Out** with GPS (the geo-fence decides
punch-in, same as the web). Permissions, visibility and actions are the web's, server-side.

## Build the APK (Android Studio)

1. Android Studio → **File → Open** → this folder (`mobile\employee-android`). Let it finish
   *Gradle sync* (the first time it downloads Gradle and Android SDK 34).
2. **Build → Build App Bundle(s) / APK(s) → Build APK(s)**. The APK is
   `app\build\outputs\apk\debug\app-debug.apk`, and is copied automatically to
   `C:\SmartPRS3-Releases\Mobile-Apps\SmartPRS3 Mobile app\v<version>\` — copy it to a phone to test.
3. For distribution: **Build → Generate Signed App Bundle / APK** with your release keystore.
   Keep the same keystore for every update.

## Testing on the office LAN

`http://192.168.0.100:8080/SmartPRS3/public` works for sign-in, but phones only give location to
**https** pages, so test Punch In with the https address (e.g. `https://ibrisk.enquiro.app/c/ibrisk`).
