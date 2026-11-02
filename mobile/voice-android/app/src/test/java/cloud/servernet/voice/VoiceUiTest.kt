package cloud.servernet.voice

import android.app.Application
import android.graphics.Bitmap
import android.graphics.Canvas
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.graphics.asAndroidBitmap
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.unit.LayoutDirection
import cloud.servernet.voice.data.*
import cloud.servernet.voice.ui.*
import org.junit.Assert.*
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import androidx.test.runner.lifecycle.ActivityLifecycleMonitorRegistry
import androidx.test.runner.lifecycle.Stage
import java.io.File

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35], application = Application::class, qualifiers = "w411dp-h891dp-xxhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class VoiceUiTest {
    @get:Rule val compose = createComposeRule()
    private val profile = SipProfile(id = "fixture", business = "استودیو آبی", username = "fixture", password = "TEST-ONLY", server = "pbx.example.test:7102", extension = "100", lineLabel = "021 0000 0000")
    private fun show(model: VoiceUiModel = VoiceUiModel(), actions: VoiceActions = VoiceActions()) {
        compose.setContent { CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Rtl) { VoiceScreen(model, actions) } }
    }
    private fun capture(name: String) {
        val directory = File("build/voice-screenshots").apply { mkdirs() }
        // Draw the actual Android view tree; Robolectric has no real PixelCopy surface.
        compose.runOnIdle {
            val activity = ActivityLifecycleMonitorRegistry.getInstance().getActivitiesInStage(Stage.RESUMED).single()
            val view = activity.window.decorView
            val bitmap = Bitmap.createBitmap(view.width, view.height, Bitmap.Config.ARGB_8888)
            view.draw(Canvas(bitmap))
            File(directory, "$name.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
            bitmap.recycle()
        }
    }
    @Test fun `dial pad edits numbers and requires explicit call tap`() {
        var dialed = ""
        show(VoiceUiModel(listOf(profile), profile.id, PhoneState(Registration.ONLINE, profile.id)), VoiceActions(dial = { dialed = it }))
        compose.onNodeWithTag("digit_1").performScrollTo().performClick()
        compose.onNodeWithTag("digit_0").performScrollTo().performClick()
        compose.onNodeWithTag("digit_0").performScrollTo().performClick()
        compose.onNodeWithTag("dial_display").assertTextEquals("100")
        assertEquals("", dialed)
        compose.onNodeWithTag("delete_digit").performScrollTo().performClick()
        compose.onNodeWithTag("dial_display").assertTextEquals("10")
        compose.onNodeWithTag("start_call").performClick()
        assertEquals("10", dialed)
    }
    @Test fun `profile editor validates before it can save a phone identity`() {
        var saved = false
        show(actions = VoiceActions(saveProfile = { saved = true; true }))
        compose.onNodeWithTag("tab_accounts").performClick()
        compose.onNodeWithTag("add_profile").performScrollTo().performClick()
        compose.onNodeWithTag("save_profile").performScrollTo().performClick()
        assertFalse(saved)
        compose.onNodeWithTag("business_name").performScrollTo().performTextInput("Example")
        compose.onNodeWithTag("sip_username").performScrollTo().performTextInput("example.100")
        compose.onNodeWithTag("sip_password").performScrollTo().performTextInput("fixture-only")
        compose.onNodeWithTag("sip_server").performScrollTo().performTextInput("pbx.example.test:7102")
        compose.onNodeWithTag("save_profile").performScrollTo().performClick()
        assertTrue(saved)
    }
    @Test fun `contacts visible in UI belong only to selected workspace`() {
        show(VoiceUiModel(listOf(profile), profile.id, contacts = listOf(Contact(profileId = profile.id, name = "Visible Person", number = "101"), Contact(profileId = "other", name = "Other Business Secret", number = "201"))))
        compose.onNodeWithTag("tab_contacts").performClick()
        compose.onNodeWithText("Visible Person").assertExists()
        compose.onNodeWithText("Other Business Secret").assertDoesNotExist()
    }
    @Test @Config(qualifiers = "fa-rIR-w411dp-h891dp-xxhdpi") fun `render Persian dialer`() {
        show(VoiceUiModel(listOf(profile), profile.id, PhoneState(Registration.ONLINE, profile.id)))
        compose.onNodeWithTag("digit_1").performScrollTo().performClick()
        compose.onNodeWithTag("digit_0").performScrollTo().performClick()
        compose.onNodeWithTag("digit_1").performScrollTo().performClick()
        capture("dialer-fa")
    }
    @Test @Config(qualifiers = "en-rUS-w411dp-h891dp-xxhdpi") fun `render English empty state`() {
        compose.setContent { VoiceScreen(VoiceUiModel(language = "en"), VoiceActions()) }
        capture("onboarding-en")
    }
    @Test @Config(qualifiers = "tr-rTR-w411dp-h891dp-xxhdpi") fun `render Turkish accounts`() {
        compose.setContent { VoiceScreen(VoiceUiModel(listOf(profile.copy(business = "Mavi Stüdyo")), profile.id, PhoneState(Registration.ONLINE, profile.id), language = "tr"), VoiceActions()) }
        compose.onNodeWithTag("tab_accounts").performClick()
        capture("accounts-tr")
    }
    @Test @Config(qualifiers = "fa-rIR-w411dp-h891dp-xxhdpi") fun `render active internet call`() {
        show(VoiceUiModel(listOf(profile), profile.id, PhoneState(Registration.ONLINE, profile.id, LiveCall("fixture-call", profile.id, "101", false, CallPhase.ACTIVE, startedAt = android.os.SystemClock.elapsedRealtime() - 92000)), contacts = listOf(Contact(profileId = profile.id, name = "تیم پشتیبانی", number = "101", extension = true))))
        capture("call-fa")
    }
}
