# ServerNet Voice

A Persian-first Android cloud-phone client for ServerNet customers and staff.
Native Kotlin, Jetpack Compose and liblinphone; Android 9+; Persian, English and Turkish.

## What the first version does

- Real SIP registration and audio calls over TLS, TCP or UDP. Connection readiness comes from the SIP registration callback, not from saving the form.
- One explicitly selected business account at a time. Switching an account is blocked during a call and unregisters the previous account before registering another one.
- A dial pad for public numbers, extensions and PBX feature codes; Persian/Arabic digits are normalized without country or dial-plan rewriting.
- Incoming call notification and Android self-managed Telecom integration; microphone, speaker, hold/resume and in-call DTMF.
- Local contacts, favorites, team extensions and call history scoped to the selected account.
- An AES-GCM encrypted, atomically written account/contact/history vault, with its AES key in Android Keystore. Android backup is disabled. Corrupt/unreadable vaults are never silently overwritten.
- A blue/cyan ServerNet design based on the existing website favicon and the openly licensed Vazirmatn typeface.

No global provider API key is included in the app. Each installation uses its own SIP account credentials. Outgoing calls require both user action in the app and a single-use authorization token checked again when Android Telecom invokes the connection service.

## Build

Requirements: JDK 17, Android SDK platform 36, build tools 35.0.0 and internet access to Google Maven, Maven Central and the Linphone Maven repository.

```sh
./gradlew :app:testDebugUnitTest :app:lintDebug :app:assemblePreview
```

The Gradle distribution and dependencies are pinned. The wrapper checks the Gradle ZIP's SHA-256. Review APK: `app/build/outputs/apk/preview/app-preview.apk`. This is a minified, debug-key-signed preview build for ARM64 and ARMv7 phones; production signing remains a separate owner-controlled release step. Do not commit keystores or signing secrets. `assembleDebug` additionally builds an unminified development package with emulator architectures.

## Connect a phone account

1. Obtain the individual SIP username, password, hostname/port, supported transport, extension and outgoing-line assignment from the customer's phone service.
2. Open **Workspace → Connect your phone**. Add the business name and connection details. TLS is the default; use the transport and port actually supplied with that account.
3. Tap **Connect** and grant microphone access. Notifications expose the current connection and incoming calls; Bluetooth permission supports connected headsets.
4. Wait for **Ready to call**, enter a number or extension and tap **Start call**.
5. Verify caller ID, two-way audio, DTMF, hold/resume, background behavior and incoming calls using the approved test line and two test devices. Do not use production customer numbers for automated tests.

The optional cloud-phone-number field is a local display label. Caller ID and outgoing-line authorization are controlled by the customer's PBX service, not by editing that label.

The observed provider portal has a SIP setup page under **My profile → Extension and Sipphone**. Its outgoing-line selector and password must be configured before a real outgoing-call acceptance test. Provider connection details are deployment/account data and are deliberately not hardcoded here.

## Product boundary

This is a working client implementation and a review build, not a completed commercial rollout. ServerNet account/password/OTP login, sales, billing, SIP provisioning, server-side customer/seat authorization, remote contact synchronization and push wake-up after process termination are not implemented in this module. There are no placeholder buttons pretending to perform those operations.

The first version signs in with individual phone credentials. A later authenticated ServerNet provisioning API should deliver only the requesting customer's authorized SIP seat, with revocation, entitlement checks and audit. Never reuse the admin click-to-call API token as an app login credential or put it in the APK.

Receiving calls requires the selected account to remain connected with the foreground connection service. Force-stop, device power management, process death, network conditions and provider reachability require physical-device acceptance. No live SIP registration or billable call is performed by the automated tests.

The existing Laravel admin/Bale click-to-call workflow remains a separate implementation. This module neither changes its route nor deploys changes to the website, relay or upstream account.

## Validation

`PhoneRulesTest` checks dial/URI boundaries and profile scoping. `VoiceRepositoryTest` checks encrypted persistence, authenticated-encryption tamper detection, account removal and late-call isolation. `VoiceUiTest` checks actual Compose interactions and produces native UI captures with fixture data under `app/build/voice-screenshots/`. `LaunchTest` opens the actual activity/application and verifies that first launch remains disconnected without initiating a call.

The GitHub workflow builds review artifacts without production credentials or deployment jobs.

## Licenses

This new mobile module is provided under GPL-3.0-or-later, consistent with the GPL distribution of liblinphone. This does not change the license of the existing website or other repository modules. See `LICENSE` and `licenses/THIRD-PARTY.md`. A closed-source distribution using the commercial Linphone SDK would require its separate commercial license.
