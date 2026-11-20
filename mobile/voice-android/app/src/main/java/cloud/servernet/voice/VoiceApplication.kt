package cloud.servernet.voice

import android.app.Application
import cloud.servernet.voice.data.VoiceRepository
import cloud.servernet.voice.phone.VoiceController

class VoiceApplication : Application() {
    val repository by lazy { VoiceRepository(this) }
    val phone by lazy { VoiceController(this, repository) }
}
