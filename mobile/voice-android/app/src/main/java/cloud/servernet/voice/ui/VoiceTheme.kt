package cloud.servernet.voice.ui

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp
import cloud.servernet.voice.R

val Ink = Color(0xFF090F1F)
val Panel = Color(0xFF121D30)
val PanelSoft = Color(0xFF17243A)
val Border = Color(0xFF28374D)
val Blue = Color(0xFF3B82F6)
val Cyan = Color(0xFF22D3EE)
val Mint = Color(0xFF65E9BB)
val Muted = Color(0xFF93A5BE)
val Paper = Color(0xFFF3F7FF)
val Danger = Color(0xFFFF7589)
val VoiceFont = FontFamily(Font(R.font.vazirmatn_regular), Font(R.font.vazirmatn_bold, FontWeight.Bold))

@Composable
fun VoiceTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = darkColorScheme(primary = Cyan, onPrimary = Ink, secondary = Mint, background = Ink,
            surface = Panel, surfaceVariant = PanelSoft, onSurface = Paper, onBackground = Paper,
            onSurfaceVariant = Muted, outline = Border, error = Danger),
        typography = Typography(
            headlineLarge = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Bold, fontSize = 30.sp, lineHeight = 44.sp),
            headlineMedium = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Bold, fontSize = 25.sp, lineHeight = 38.sp),
            titleLarge = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Bold, fontSize = 20.sp, lineHeight = 30.sp),
            titleMedium = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Bold, fontSize = 16.sp, lineHeight = 26.sp),
            bodyLarge = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Normal, fontSize = 16.sp, lineHeight = 27.sp),
            bodyMedium = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Normal, fontSize = 14.sp, lineHeight = 24.sp),
            bodySmall = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Normal, fontSize = 12.sp, lineHeight = 21.sp),
            labelLarge = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Bold, fontSize = 14.sp, lineHeight = 24.sp),
            labelMedium = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Normal, fontSize = 12.sp, lineHeight = 20.sp),
            labelSmall = TextStyle(fontFamily = VoiceFont, fontWeight = FontWeight.Normal, fontSize = 10.sp, lineHeight = 16.sp),
        ), content = content
    )
}
