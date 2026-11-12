# ServerNet Voice Android — review build

Date: 2026-11-02. Base branch: `develop`.
Base SHA: `d757b2ebc3a7b64f57cafdaa5b61151669fbb994`.
Review: [draft PR 213](https://github.com/servernetir/server/pull/213), branch
`feat/servernet-voice-android`; the review PR records the final source SHA.

## Scope and product behavior

The existing admin/Bale cloud-phone implementation connects two ordinary phone
legs. The new, separate Android client uses an individual SIP seat to carry the
operator's audio over the internet instead of calling their SIM first.

`mobile/voice-android/` is a native Kotlin/Compose application with ServerNet's
existing blue/cyan server-stack mark, Vazirmatn, Persian RTL and English/Turkish
translations. The implemented paths include SIP registration, outgoing and
incoming audio calls, public numbers and extensions, DTMF, mute, speaker,
hold/resume, local contacts/favorites and call history. One business profile is
registered at a time; switching is blocked during a call. Contacts and history
are scoped to their profile.

The UI reports readiness from the native registration callback. Saving a
password does not imply connection, nor does a call invite imply answered audio.
No demonstration account, real customer data, provider-wide API key or production
credential is shipped. The screen captures use fictional fixture data and do not
prove a live registration or call.

## Account and Android boundaries

- Login currently means an individual SIP username/password and endpoint.
  ServerNet web-account/OTP login, customer entitlements, automatic SIP seat
  provisioning, sales, billing and remote directory sync are subsequent backend
  work. The app does not present fake controls for those operations.
- The AES-GCM vault is written atomically, with its key in Android Keystore;
  accounts, contacts and history are encrypted. Backup is disabled and corrupt
  ciphertext is not replaced silently. Native plaintext auth/history stores
  are disabled. Certificate verification is enabled; TLS is the form default.
- Calls use self-managed Android Telecom and a phone-call foreground service.
  `CALL_PHONE`, contacts and SMS permissions are absent. An outgoing connection
  must also consume a short-lived, single-use app authorization bound to the
  selected account and number.
- Incoming calls require an active connection/service. Push wake-up after
  process death is not implemented. Force-stop, OEM battery restrictions,
  mobile-network changes and audio/headset behavior require device acceptance.
- The preview is signed with a development key. Production signing and store
  release remain owner-controlled release steps.

## Provider evidence and required live acceptance

The official [SIP setup guide](https://daftareshoma.com/help/docs/kb/portal-setting/sipphone-setting/)
and [colleague settings](https://daftareshoma.com/help/docs/kb/portal-setting/edite-colleague-information/)
describe softphone access. The existing callback API documentation is not an
audio transport or a substitute for a SIP seat.

The owner's authenticated portal has **My profile → Extension and Sipphone** at
`/profilePanel/sipPhone`. During the read-only inspection, a usable SIP password
and an outgoing-line selection were not available. No credentials were generated
or changed, no settings saved, and no billable call was placed. Endpoint values
are account/deployment data and are not hardcoded in the client.

To accept live service: assign an authorized outgoing line and individual SIP
credentials, install the review APK on a test phone, connect, and verify the
registration callback, caller ID, two-way audio, inbound/outbound calls,
extensions, DTMF, hold/resume, headsets, background delivery and Wi-Fi/mobile
handover using approved test destinations. An online UI fixture is not evidence
for any of those live checks.

## Validation actually performed

Isolated checkout; JDK 17.0.21, Gradle 8.13, Android SDK 36/build tools 35.0.0.
The command below completed successfully on the implemented source:

```sh
./gradlew --no-daemon --max-workers=2 :app:testDebugUnitTest :app:lintDebug :app:assemblePreview
```

- **19 tests, zero failures/errors/skips:** 7 dial/profile rule tests, 4 encrypted
  repository tests, 7 Compose behavior/capture tests, and 1 actual activity launch
  smoke test. Robolectric uses native Android graphics, SDK 35. These are JVM
  tests; they do not exercise a physical microphone or a real SIP server.
- Android lint: **0 errors, 5 warnings** (API-gated locale attribute, phone-only
  ABI/ChromeOS guidance, redundant minimum-SDK icon directory, monochrome icon
  guidance with a v33 override already supplied, and a KTX style suggestion).
- Native UI captures in Persian, English and Turkish were visually inspected;
  the connected call capture also uses fixture data.
- Final preview APK: package `cloud.servernet.voice`, version `0.1.0-preview`,
  min SDK 28 / Android 9, target SDK 36; ARM64 and ARMv7. Size **31,338,219 bytes**.
  APK v2 signature verification passed. ARM64 native ELF LOAD alignment is at
  least 16 KiB. The merged APK permission list was inspected.
- APK SHA-256:
  `1549fe39c3270beecdb775fd61e0dbc2e4c4892f727c01c15cb872efcbf17eda`.

Physical-device/native SIP acceptance is pending, not passed. The Android-only
workflow builds test reports, fixture screenshots and a review APK without
production credentials or a deployment job; its remote result must be checked
separately from the local result above.

The first GitHub run stopped before tests because the fresh runner did not expose
`sdkmanager` on PATH. The workflow now explicitly installs the Android command-line
tools and the pinned SDK/build-tool packages with `android-actions/setup-android`.
The final remote run and its exact tested SHA are recorded in PR 213; the failed
setup run must not be counted as a successful validation.

## Files, licensing, publication and rollback

The change adds `mobile/voice-android/`, its Android CI workflow, this report and
one entry/row in the project index. It does not modify the Laravel application,
legacy root `app/`, database/schema, admin callback, Bale relay, upstream account,
infrastructure, financial settings or production service.

The mobile module is GPL-3.0-or-later and pins the audio-only Linphone SDK
5.4.129; this version's Maven POM declares GPLv3. The bundled Vazirmatn 33.003
fonts include their SIL OFL notice. Existing repository modules' licenses are
unchanged. Commercial closed-source distribution requires a separately licensed
SDK arrangement; see the module's `licenses/THIRD-PARTY.md`.

State: built and tested as a review candidate, with source in draft PR 213 to
`develop`. No production deployment, store publication, customer rollout,
or live call is claimed. Repository code is delivered through the review branch;
the review APK and native UI previews are separate deliverables.

Before rollout, rollback means closing/reverting the isolated PR. After a test
installation, disconnect the SIP account and uninstall the preview if needed;
uninstalling removes the local encrypted data and Keystore key. Keep approved
credentials available from the service's secure account management. No server
or database rollback is required for this change.
