package cloud.servernet.voice

import android.os.Looper
import cloud.servernet.voice.data.Registration
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35], application = VoiceApplication::class, qualifiers = "fa-rIR-w411dp-h891dp-xxhdpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class LaunchTest {
    @Test fun `real launcher opens in Persian and stays offline until the user connects`() {
        val controller = Robolectric.buildActivity(MainActivity::class.java).setup()
        try {
            Shadows.shadowOf(Looper.getMainLooper()).idle()
            val activity = controller.get()
            val application = activity.application as VoiceApplication
            assertTrue(activity.window.decorView.isAttachedToWindow)
            assertEquals("fa", activity.resources.configuration.locales[0].language)
            assertTrue(application.repository.profiles.value.isEmpty())
            assertEquals(Registration.OFFLINE, application.phone.state.value.registration)
            assertNull(application.phone.state.value.call)
        } finally { controller.pause().stop().destroy() }
    }
}
