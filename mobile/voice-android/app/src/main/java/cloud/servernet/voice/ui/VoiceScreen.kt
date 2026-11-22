package cloud.servernet.voice.ui

import android.os.SystemClock
import androidx.compose.animation.AnimatedContent
import androidx.compose.foundation.*
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import cloud.servernet.voice.R
import cloud.servernet.voice.BuildConfig
import cloud.servernet.voice.data.*
import kotlinx.coroutines.delay
import java.text.DateFormat
import java.util.Date

data class VoiceUiModel(
    val profiles: List<SipProfile> = emptyList(), val selectedId: String? = null,
    val phone: PhoneState = PhoneState(), val contacts: List<Contact> = emptyList(),
    val calls: List<CallEntry> = emptyList(), val language: String = "fa",
) {
    val selected get() = profiles.firstOrNull { it.id == selectedId }
    val ownContacts get() = ProfileScope.contacts(contacts, selectedId)
    val ownCalls get() = ProfileScope.calls(calls, selectedId).sortedByDescending { it.at }
}

data class VoiceActions(
    val connect: () -> Unit = {}, val disconnect: () -> Unit = {}, val dial: (String) -> Unit = {},
    val select: (String) -> Unit = {}, val saveProfile: (SipProfile) -> Boolean = { true },
    val removeProfile: (String) -> Unit = {}, val saveContact: (Contact) -> Boolean = { true },
    val removeContact: (Contact) -> Unit = {}, val favorite: (Contact) -> Unit = {},
    val language: (String) -> Unit = {}, val dismissError: () -> Unit = {},
    val answer: () -> Unit = {}, val hangUp: () -> Unit = {}, val mute: () -> Unit = {},
    val speaker: () -> Unit = {}, val hold: () -> Unit = {}, val dtmf: (Char) -> Unit = {},
)

private enum class Tab(val title: Int, val icon: ImageVector, val tag: String) {
    DIAL(R.string.dialer, Icons.Outlined.Dialpad, "dialer"),
    PEOPLE(R.string.contacts, Icons.Outlined.PeopleOutline, "contacts"),
    RECENT(R.string.history, Icons.Outlined.History, "history"),
    ACCOUNTS(R.string.accounts, Icons.Outlined.Workspaces, "accounts")
}

@Composable
fun VoiceScreen(model: VoiceUiModel, actions: VoiceActions) {
    var tab by rememberSaveable { mutableIntStateOf(0) }
    var number by rememberSaveable { mutableStateOf("") }
    var editor by remember { mutableStateOf(false) }
    var editing by remember { mutableStateOf<SipProfile?>(null) }
    var addPerson by remember { mutableStateOf(false) }
    var internalPerson by remember { mutableStateOf(false) }
    var remove by remember { mutableStateOf<SipProfile?>(null) }
    val addAccount = { editing = null; editor = true }
    val pickNumber: (String) -> Unit = { number = it; tab = 0 }

    VoiceTheme {
        if (model.phone.call != null) {
            CallScreen(model.phone.call, model.ownContacts, actions)
        } else Scaffold(
            containerColor = Ink,
            bottomBar = {
                Surface(color = Ink, tonalElevation = 0.dp) {
                    Row(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 12.dp, vertical = 10.dp)) {
                        Tab.entries.forEachIndexed { index, item ->
                            val selected = tab == index
                            Column(Modifier.weight(1f).clip(RoundedCornerShape(18.dp))
                                .background(if (selected) PanelSoft else Color.Transparent)
                                .clickable { tab = index }.padding(vertical = 10.dp).testTag("tab_${item.tag}"),
                                horizontalAlignment = Alignment.CenterHorizontally) {
                                Icon(item.icon, stringResource(item.title), tint = if (selected) Cyan else Muted, modifier = Modifier.size(23.dp))
                                Spacer(Modifier.height(4.dp))
                                Text(stringResource(item.title), style = MaterialTheme.typography.labelMedium, color = if (selected) Paper else Muted)
                            }
                        }
                    }
                }
            }
        ) { padding ->
            Column(Modifier.fillMaxSize().padding(padding).statusBarsPadding()) {
                BrandHeader(model.selected?.business) { tab = 3 }
                model.phone.error?.let { ErrorBanner(it, actions.dismissError) }
                AnimatedContent(targetState = tab, modifier = Modifier.weight(1f), label = "workspace") { page ->
                    when (page) {
                        0 -> DialerScreen(model, number, { number = it }, addAccount, actions, pickNumber)
                        1 -> PeopleScreen(model, actions, pickNumber, { internalPerson = it; addPerson = true }, addAccount)
                        2 -> HistoryScreen(model, pickNumber)
                        else -> AccountsScreen(model, actions, addAccount, { editing = it; editor = true }, { remove = it })
                    }
                }
            }
        }
        if (editor) ProfileEditor(editing, { editor = false }, { if (actions.saveProfile(it)) editor = false })
        if (addPerson && model.selectedId != null) ContactEditor(model.selectedId, internalPerson, { addPerson = false }, { if (actions.saveContact(it)) addPerson = false })
        remove?.let { profile ->
            AlertDialog(onDismissRequest = { remove = null }, title = { Text(stringResource(R.string.remove_account)) },
                text = { Text(stringResource(R.string.remove_account_body)) }, confirmButton = {
                    TextButton(onClick = { actions.removeProfile(profile.id); remove = null }) { Text(stringResource(R.string.remove), color = Danger) }
                }, dismissButton = { TextButton(onClick = { remove = null }) { Text(stringResource(R.string.cancel)) } })
        }
    }
}

@Composable
private fun BrandHeader(business: String?, onAccount: () -> Unit) {
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Row(Modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 16.dp), verticalAlignment = Alignment.CenterVertically) {
            Image(painterResource(R.drawable.ic_servernet), "ServerNet", Modifier.size(39.dp).clip(RoundedCornerShape(11.dp)))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Text("ServerNet", color = Paper, fontWeight = FontWeight.Bold, fontSize = 19.sp)
                Text("V O I C E", color = Muted, fontSize = 10.sp, letterSpacing = 2.sp)
            }
            IconButton(onClick = onAccount, modifier = Modifier.size(44.dp).clip(CircleShape).background(Panel)) {
                if (business != null) Text(business.take(1), color = Cyan, fontWeight = FontWeight.Bold)
                else Icon(Icons.Outlined.PersonOutline, stringResource(R.string.accounts), tint = Muted)
            }
        }
    }
}

@Composable
private fun DialerScreen(model: VoiceUiModel, number: String, onNumber: (String) -> Unit, addAccount: () -> Unit, actions: VoiceActions, pick: (String) -> Unit) {
    var internal by rememberSaveable { mutableStateOf(false) }
    Column(Modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally) {
    LazyColumn(Modifier.weight(1f).testTag("dialer_screen"), contentPadding = PaddingValues(horizontal = 24.dp, vertical = 4.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        item { AccountHero(model, if (model.selected == null) addAccount else actions.connect) }
        item {
            Row(Modifier.padding(top = 22.dp).clip(RoundedCornerShape(14.dp)).background(Panel).padding(4.dp)) {
                listOf(R.string.phone_number, R.string.internal_call).forEachIndexed { index, key ->
                    Box(Modifier.clip(RoundedCornerShape(11.dp)).background(if (internal == (index == 1)) PanelSoft else Color.Transparent)
                        .clickable { internal = index == 1 }.padding(horizontal = 20.dp, vertical = 9.dp), contentAlignment = Alignment.Center) {
                        Text(stringResource(key), color = if (internal == (index == 1)) Paper else Muted, style = MaterialTheme.typography.labelMedium)
                    }
                }
            }
        }
        item {
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(Modifier.fillMaxWidth().height(75.dp), verticalAlignment = Alignment.CenterVertically) {
                    Spacer(Modifier.width(42.dp))
                    Text(number.ifEmpty { stringResource(if (internal) R.string.extension_hint else R.string.number_hint) },
                        Modifier.weight(1f).testTag("dial_display"), color = if (number.isEmpty()) Muted else Paper,
                        fontSize = if (number.isEmpty()) 19.sp else 30.sp, fontWeight = FontWeight.Bold,
                        textAlign = TextAlign.Center, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    IconButton(onClick = { onNumber(number.dropLast(1)) }, modifier = Modifier.size(42.dp).testTag("delete_digit")) {
                        Icon(Icons.AutoMirrored.Outlined.Backspace, stringResource(R.string.delete_digit), tint = Muted, modifier = Modifier.size(20.dp))
                    }
                }
            }
        }
        item { Keypad(onKey = { if (number.length < 32) onNumber(number + it) }, onLongZero = { if (number.isEmpty()) onNumber("+") }) }
        if (model.ownContacts.any { it.favorite }) item {
            Column(Modifier.fillMaxWidth().padding(bottom = 24.dp)) {
                Text(stringResource(R.string.favorites), style = MaterialTheme.typography.labelMedium, color = Muted)
                Spacer(Modifier.height(12.dp))
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.horizontalScroll(rememberScrollState())) {
                    model.ownContacts.filter { it.favorite }.forEach { contact ->
                        AssistChip(onClick = { pick(contact.number) }, label = { Text(contact.name) }, leadingIcon = { Icon(if (contact.extension) Icons.Outlined.SupportAgent else Icons.Outlined.PersonOutline, null, Modifier.size(18.dp)) })
                    }
                }
            }
        }
    }
    val connected = model.phone.registration == Registration.ONLINE && model.phone.profileId == model.selectedId
    val label = if (model.selected == null) R.string.add_account else if (!connected) R.string.connect else R.string.call
    GradientButton(stringResource(label), Icons.Outlined.Call, Modifier.fillMaxWidth().padding(horizontal = 24.dp).padding(top = 8.dp).testTag("start_call"),
        enabled = model.phone.registration != Registration.CONNECTING && (!connected || number.isNotEmpty())) {
        when { model.selected == null -> addAccount(); !connected -> actions.connect(); else -> actions.dial(number) }
    }
    Text(stringResource(R.string.call_hint), color = Muted, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp, bottom = 6.dp))
    }
}

@Composable
private fun AccountHero(model: VoiceUiModel, connect: () -> Unit) {
    val active = model.phone.profileId == model.selectedId && model.phone.registration == Registration.ONLINE
    Box(Modifier.fillMaxWidth().clip(RoundedCornerShape(26.dp))
        .background(Brush.linearGradient(listOf(Color(0xFF142C4A), Color(0xFF142439), Panel)))
        .border(1.dp, Color(0xFF294766), RoundedCornerShape(26.dp)).padding(22.dp)) {
        Column {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                StatusPill(if (model.selected == null) Registration.OFFLINE else model.phone.registration)
                Spacer(Modifier.weight(1f))
                WaveMark(active)
            }
            Spacer(Modifier.height(24.dp))
            Text(model.selected?.business ?: stringResource(R.string.no_account), color = Paper,
                style = MaterialTheme.typography.titleLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.height(4.dp))
            if (model.selected == null) {
                Text(stringResource(R.string.no_account_body), style = MaterialTheme.typography.bodySmall, color = Muted)
            } else {
                val profile = model.selected!!
                Text(profile.lineLabel.ifEmpty { stringResource(R.string.extension) + "  " + profile.extension }, color = Muted,
                    style = if (profile.lineLabel.isNotEmpty()) MaterialTheme.typography.bodyMedium.copy(textDirection = androidx.compose.ui.text.style.TextDirection.Ltr) else MaterialTheme.typography.bodyMedium)
            }
        }
    }
}

@Composable
private fun WaveMark(active: Boolean, large: Boolean = false) {
    val heights = listOf(12, 25, 17, 36, 24, 44, 31, 17, 27, 11)
    Row(horizontalArrangement = Arrangement.spacedBy(if (large) 7.dp else 3.dp), verticalAlignment = Alignment.CenterVertically, modifier = Modifier.height(if (large) 90.dp else 44.dp)) {
        heights.forEachIndexed { index, height ->
            Box(Modifier.width(if (large) 5.dp else 3.dp).height((height * if (large) 2 else 1).dp).clip(CircleShape)
                .background(if (active) if (index % 3 == 0) Blue else Cyan.copy(alpha = 0.65f) else Muted.copy(alpha = 0.35f)))
        }
    }
}

@Composable
private fun StatusPill(status: Registration) {
    val color = when (status) { Registration.ONLINE -> Mint; Registration.FAILED -> Danger; Registration.CONNECTING -> Cyan; else -> Muted }
    val label = when (status) { Registration.ONLINE -> R.string.online; Registration.CONNECTING -> R.string.connecting; Registration.FAILED -> R.string.connection_failed; else -> R.string.offline }
    Row(Modifier.clip(CircleShape).background(color.copy(alpha = 0.10f)).padding(horizontal = 10.dp, vertical = 6.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
        Box(Modifier.size(5.dp).clip(CircleShape).background(color))
        Text(stringResource(label), color = color, style = MaterialTheme.typography.labelSmall)
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun Keypad(onKey: (String) -> Unit, onLongZero: () -> Unit = {}) {
    val keys = listOf("1" to "", "2" to "ABC", "3" to "DEF", "4" to "GHI", "5" to "JKL", "6" to "MNO", "7" to "PQRS", "8" to "TUV", "9" to "WXYZ", "*" to "", "0" to "+", "#" to "")
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            keys.chunked(3).forEach { row ->
                Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    row.forEach { (digit, letters) ->
                        Column(Modifier.weight(1f).height(58.dp).clip(RoundedCornerShape(22.dp)).background(Panel)
                            .combinedClickable(onClick = { onKey(digit) }, onLongClick = if (digit == "0") onLongZero else null)
                            .testTag("digit_$digit"), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                            Text(digit, color = Paper, fontSize = 26.sp, lineHeight = 32.sp, fontWeight = FontWeight.Normal)
                            if (letters.isNotEmpty()) Text(letters, color = Muted, fontSize = 8.sp, lineHeight = 10.sp, letterSpacing = 2.sp)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun PeopleScreen(model: VoiceUiModel, actions: VoiceActions, pick: (String) -> Unit, add: (Boolean) -> Unit, addAccount: () -> Unit) {
    var query by rememberSaveable { mutableStateOf("") }
    var extensions by rememberSaveable { mutableStateOf(false) }
    val list = model.ownContacts.filter { it.extension == extensions && (it.name.contains(query, true) || it.number.contains(query)) }.sortedBy { it.name }
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item { ScreenTitle(stringResource(R.string.contacts), stringResource(R.string.account_privacy)) }
        item { OutlinedTextField(query, { query = it }, Modifier.fillMaxWidth().testTag("contact_search"), placeholder = { Text(stringResource(R.string.search)) }, leadingIcon = { Icon(Icons.Outlined.Search, null) }, singleLine = true, shape = RoundedCornerShape(17.dp)) }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(!extensions, { extensions = false }, { Text(stringResource(R.string.all_contacts)) })
                FilterChip(extensions, { extensions = true }, { Text(stringResource(R.string.extensions)) })
            }
        }
        item { OutlinedButton(onClick = { if (model.selected == null) addAccount() else add(extensions) }, modifier = Modifier.fillMaxWidth().testTag("add_contact"), shape = RoundedCornerShape(16.dp)) {
            Icon(Icons.Outlined.Add, null, Modifier.size(20.dp)); Spacer(Modifier.width(8.dp)); Text(stringResource(if (extensions) R.string.add_extension else R.string.add_contact))
        } }
        if (list.isEmpty()) item { EmptyState(Icons.Outlined.PeopleOutline, stringResource(if (query.isNotEmpty()) R.string.no_results else R.string.no_contacts), stringResource(if (model.selected == null) R.string.no_profile_contact else R.string.no_contacts_body)) }
        items(list, key = { it.id }) { contact ->
            Surface(shape = RoundedCornerShape(20.dp), color = Panel) {
                Row(Modifier.fillMaxWidth().clickable { pick(contact.number) }.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Avatar(contact.name, contact.extension)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(contact.name, style = MaterialTheme.typography.titleMedium)
                        Text(contact.number, color = Muted, style = MaterialTheme.typography.bodySmall.copy(textDirection = androidx.compose.ui.text.style.TextDirection.Ltr))
                    }
                    IconButton(onClick = { actions.favorite(contact) }) { Icon(if (contact.favorite) Icons.Outlined.Star else Icons.Outlined.StarOutline, stringResource(if (contact.favorite) R.string.unfavorite else R.string.favorite), tint = if (contact.favorite) Cyan else Muted) }
                    IconButton(onClick = { actions.removeContact(contact) }) { Icon(Icons.Outlined.Close, stringResource(R.string.delete_contact), tint = Muted, modifier = Modifier.size(18.dp)) }
                }
            }
        }
    }
}

@Composable
private fun HistoryScreen(model: VoiceUiModel, pick: (String) -> Unit) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        item { ScreenTitle(stringResource(R.string.history), model.selected?.business ?: stringResource(R.string.brand_tagline)) }
        if (model.ownCalls.isEmpty()) item { EmptyState(Icons.Outlined.History, stringResource(R.string.no_history), stringResource(R.string.no_history_body)) }
        items(model.ownCalls, key = { it.id }) { call ->
            val name = model.ownContacts.firstOrNull { it.number == call.number }?.name ?: call.number
            val color = if (call.answered) if (call.incoming) Mint else Cyan else Danger
            Row(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(Panel).clickable { pick(call.number) }.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                Icon(if (call.incoming) Icons.AutoMirrored.Outlined.CallReceived else Icons.AutoMirrored.Outlined.CallMade, null, tint = color, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f)) {
                    Text(name, maxLines = 1, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.titleMedium)
                    val label = stringResource(if (!call.answered) R.string.missed else if (call.incoming) R.string.incoming else R.string.outgoing)
                    Text(label + " · " + DateFormat.getDateTimeInstance(DateFormat.SHORT, DateFormat.SHORT).format(Date(call.at)), color = Muted, style = MaterialTheme.typography.labelSmall)
                }
                Text(if (call.answered) "%d:%02d".format(call.durationSeconds / 60, call.durationSeconds % 60) else "—", color = Muted, style = MaterialTheme.typography.labelMedium)
            }
        }
    }
}

@Composable
private fun AccountsScreen(model: VoiceUiModel, actions: VoiceActions, add: () -> Unit, edit: (SipProfile) -> Unit, remove: (SipProfile) -> Unit) {
    LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(24.dp), verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item { ScreenTitle(stringResource(R.string.accounts), stringResource(R.string.account_privacy)) }
        items(model.profiles, key = { it.id }) { profile ->
            val selected = profile.id == model.selectedId
            Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(Panel)
                .border(1.dp, if (selected) Blue.copy(alpha = 0.65f) else Border, RoundedCornerShape(24.dp)).padding(18.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Avatar(profile.business, false)
                    Spacer(Modifier.width(12.dp))
                    Column(Modifier.weight(1f)) {
                        Text(profile.business, style = MaterialTheme.typography.titleMedium)
                        Text(profile.lineLabel.ifEmpty { stringResource(R.string.extension) + " " + profile.extension }, color = Muted,
                            style = if (profile.lineLabel.isNotEmpty()) MaterialTheme.typography.bodySmall.copy(textDirection = androidx.compose.ui.text.style.TextDirection.Ltr) else MaterialTheme.typography.bodySmall)
                    }
                    IconButton(onClick = { edit(profile) }) { Icon(Icons.Outlined.Edit, stringResource(R.string.edit), tint = Muted) }
                    IconButton(onClick = { remove(profile) }) { Icon(Icons.Outlined.DeleteOutline, stringResource(R.string.remove), tint = Muted) }
                }
                Spacer(Modifier.height(16.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (selected) StatusPill(model.phone.registration) else Text(stringResource(R.string.select_account), color = Muted, style = MaterialTheme.typography.labelSmall)
                    Spacer(Modifier.weight(1f))
                    TextButton(onClick = {
                        if (!selected) actions.select(profile.id)
                        else if (model.phone.registration == Registration.ONLINE) actions.disconnect() else actions.connect()
                    }) {
                        Text(stringResource(if (!selected) R.string.select_account else if (model.phone.registration == Registration.ONLINE) R.string.disconnect else R.string.connect))
                    }
                }
            }
        }
        item { GradientButton(stringResource(if (model.profiles.isEmpty()) R.string.add_account else R.string.add_another_account), Icons.Outlined.Add, Modifier.fillMaxWidth().testTag("add_profile"), onClick = add) }
        item { Text(stringResource(R.string.background_hint), style = MaterialTheme.typography.bodySmall, color = Muted) }
        item { HorizontalDivider(color = Border); Spacer(Modifier.height(18.dp)); Text(stringResource(R.string.language), style = MaterialTheme.typography.titleMedium) }
        item {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                listOf("fa" to "فارسی", "en" to "English", "tr" to "Türkçe").forEach { (code, label) ->
                    FilterChip(model.language == code, { actions.language(code) }, { Text(label) }, modifier = Modifier.testTag("language_$code"))
                }
            }
        }
        item { Text(stringResource(R.string.privacy_note), color = Muted, style = MaterialTheme.typography.bodySmall); Spacer(Modifier.height(16.dp)); Text("ServerNet Voice  ·  ${BuildConfig.VERSION_NAME}", color = Muted.copy(alpha = .65f), style = MaterialTheme.typography.labelSmall) }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ProfileEditor(profile: SipProfile?, dismiss: () -> Unit, save: (SipProfile) -> Unit) {
    var business by remember { mutableStateOf(profile?.business ?: "") }
    var username by remember { mutableStateOf(profile?.username ?: "") }
    var password by remember { mutableStateOf("") }
    var server by remember { mutableStateOf(profile?.server ?: "") }
    var extension by remember { mutableStateOf(profile?.extension ?: "") }
    var line by remember { mutableStateOf(profile?.lineLabel ?: "") }
    var transport by remember { mutableStateOf(profile?.transport ?: SipTransport.TLS) }
    var showPassword by remember { mutableStateOf(false) }
    var invalid by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = dismiss, containerColor = Ink) {
        Column(Modifier.fillMaxWidth().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 24.dp).padding(bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            ScreenTitle(stringResource(if (profile == null) R.string.add_account else R.string.edit_account), stringResource(R.string.account_intro))
            Field(business, { business = it }, R.string.business_name, "business_name")
            Field(line, { line = it }, R.string.line_label, "line_label", KeyboardType.Phone, ltr = true)
            Text(stringResource(R.string.sip_settings), style = MaterialTheme.typography.titleMedium, color = Cyan)
            Field(username, { username = it }, R.string.sip_username, "sip_username", KeyboardType.Ascii, ltr = true)
            OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth().testTag("sip_password"), label = { Text(stringResource(R.string.sip_password)) },
                supportingText = if (profile != null) { { Text(stringResource(R.string.password_keep)) } } else null,
                singleLine = true, shape = RoundedCornerShape(16.dp), visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, autoCorrectEnabled = false),
                trailingIcon = { IconButton(onClick = { showPassword = !showPassword }) { Icon(if (showPassword) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility, stringResource(if (showPassword) R.string.hide_password else R.string.show_password)) } })
            Field(server, { server = it }, R.string.sip_server, "sip_server", KeyboardType.Uri, ltr = true)
            Text(stringResource(R.string.transport), style = MaterialTheme.typography.labelMedium, color = Muted)
            CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) { SipTransport.entries.forEach { t -> FilterChip(transport == t, { transport = t }, { Text(t.name) }) } }
            }
            Field(extension, { extension = it }, R.string.extension, "sip_extension", KeyboardType.Number, ltr = true)
            if (invalid) Text(stringResource(R.string.form_error), color = Danger, style = MaterialTheme.typography.bodySmall)
            GradientButton(stringResource(R.string.save_account), Icons.Outlined.Check, Modifier.fillMaxWidth().testTag("save_profile")) {
                val candidate = SipProfile(id = profile?.id ?: java.util.UUID.randomUUID().toString(), business = business.trim(), username = username.trim(), password = password.ifEmpty { profile?.password ?: "" }, server = server.trim(), transport = transport, extension = extension.trim(), lineLabel = line.trim())
                if (runCatching { candidate.validate() }.isSuccess) save(candidate) else invalid = true
            }
            Text(stringResource(R.string.privacy_note), color = Muted, style = MaterialTheme.typography.bodySmall)
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ContactEditor(profileId: String, extension: Boolean, dismiss: () -> Unit, save: (Contact) -> Unit) {
    var name by remember { mutableStateOf("") }
    var number by remember { mutableStateOf("") }
    var invalid by remember { mutableStateOf(false) }
    ModalBottomSheet(onDismissRequest = dismiss, containerColor = Ink) {
        Column(Modifier.fillMaxWidth().imePadding().verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
            Text(stringResource(if (extension) R.string.add_extension else R.string.add_contact), style = MaterialTheme.typography.titleLarge)
            Field(name, { name = it }, R.string.contact_name, "contact_name")
            Field(number, { number = it }, if (extension) R.string.extension else R.string.phone_number, "contact_number", KeyboardType.Phone, ltr = true)
            if (invalid) Text(stringResource(R.string.form_error), color = Danger)
            GradientButton(stringResource(R.string.save), Icons.Outlined.Check, Modifier.fillMaxWidth().testTag("save_contact")) {
                if (name.trim().isNotEmpty() && runCatching { DialPlan.normalize(number) }.isSuccess) save(Contact(profileId = profileId, name = name.trim(), number = number, extension = extension)) else invalid = true
            }
        }
    }
}

@Composable
private fun CallScreen(call: LiveCall, contacts: List<Contact>, actions: VoiceActions) {
    var keypad by remember { mutableStateOf(false) }
    var seconds by remember { mutableLongStateOf(0) }
    LaunchedEffect(call.startedAt) { while (true) { seconds = if (call.startedAt > 0) ((SystemClock.elapsedRealtime() - call.startedAt) / 1000).coerceAtLeast(0) else 0; delay(500) } }
    val title = contacts.firstOrNull { it.number == call.number }?.name ?: call.number
    val status = when (call.phase) { CallPhase.INCOMING -> R.string.incoming_call; CallPhase.RINGING -> R.string.ringing; CallPhase.HELD -> R.string.on_hold; CallPhase.ACTIVE -> R.string.connected; else -> R.string.dialing }
    Column(Modifier.fillMaxSize().background(Brush.verticalGradient(listOf(Color(0xFF14243F), Ink, Ink))).systemBarsPadding().verticalScroll(rememberScrollState()).padding(28.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Text("ServerNet Voice", color = Muted, style = MaterialTheme.typography.labelLarge)
        Spacer(Modifier.height(38.dp))
        Box(Modifier.size(144.dp).clip(CircleShape).background(Cyan.copy(alpha = .06f)).border(1.dp, Cyan.copy(alpha = .14f), CircleShape), contentAlignment = Alignment.Center) { WaveMark(true, true) }
        Spacer(Modifier.height(26.dp))
        Text(title, color = Paper, style = MaterialTheme.typography.headlineMedium, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Text(stringResource(status), color = Mint, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 10.dp))
        Text(if (call.startedAt == 0L) "···" else "%02d:%02d".format(seconds / 60, seconds % 60), color = Muted, style = MaterialTheme.typography.headlineMedium, modifier = Modifier.padding(top = 8.dp))
        Spacer(Modifier.height(36.dp))
        if (call.phase != CallPhase.INCOMING) {
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
                CallControl(if (call.muted) Icons.Outlined.MicOff else Icons.Outlined.Mic, stringResource(if (call.muted) R.string.unmute else R.string.mute), call.muted, actions.mute)
                CallControl(Icons.AutoMirrored.Outlined.VolumeUp, stringResource(R.string.speaker), call.speaker, actions.speaker)
                CallControl(if (call.phase == CallPhase.HELD) Icons.Outlined.PlayArrow else Icons.Outlined.Pause, stringResource(if (call.phase == CallPhase.HELD) R.string.resume else R.string.hold), call.phase == CallPhase.HELD, actions.hold)
            }
            TextButton(onClick = { keypad = !keypad }, modifier = Modifier.padding(top = 20.dp)) { Icon(Icons.Outlined.Dialpad, null, Modifier.size(18.dp)); Spacer(Modifier.width(8.dp)); Text(stringResource(if (keypad) R.string.close_keypad else R.string.dtmf)) }
            if (keypad) Keypad({ actions.dtmf(it[0]) })
        }
        Spacer(Modifier.height(36.dp))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly) {
            CallControl(Icons.Outlined.CallEnd, stringResource(if (call.phase == CallPhase.INCOMING) R.string.decline else R.string.hang_up), true, actions.hangUp, Danger)
            if (call.phase == CallPhase.INCOMING) CallControl(Icons.Outlined.Call, stringResource(R.string.answer), true, actions.answer, Mint)
        }
        Spacer(Modifier.height(22.dp))
        Text(stringResource(R.string.hd_voice), color = Muted, style = MaterialTheme.typography.labelSmall)
    }
}

@Composable
private fun CallControl(icon: ImageVector, label: String, selected: Boolean, onClick: () -> Unit, color: Color = Cyan) {
    Column(horizontalAlignment = Alignment.CenterHorizontally) {
        FilledIconButton(onClick = onClick, modifier = Modifier.size(66.dp), colors = IconButtonDefaults.filledIconButtonColors(containerColor = if (selected) color else PanelSoft, contentColor = if (selected) Ink else Paper)) { Icon(icon, label, Modifier.size(26.dp)) }
        Text(label, color = Muted, style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
private fun Field(value: String, change: (String) -> Unit, label: Int, tag: String, keyboard: KeyboardType = KeyboardType.Text, ltr: Boolean = false) {
    OutlinedTextField(value, change, Modifier.fillMaxWidth().testTag(tag), label = { Text(stringResource(label)) }, singleLine = true, shape = RoundedCornerShape(16.dp),
        keyboardOptions = KeyboardOptions(keyboardType = keyboard, autoCorrectEnabled = false),
        textStyle = MaterialTheme.typography.bodyLarge.copy(textDirection = if (ltr) androidx.compose.ui.text.style.TextDirection.Ltr else androidx.compose.ui.text.style.TextDirection.Content))
}

@Composable
private fun GradientButton(text: String, icon: ImageVector, modifier: Modifier = Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    Box(modifier.height(59.dp).clip(RoundedCornerShape(20.dp)).background(if (enabled) Brush.horizontalGradient(listOf(Blue, Cyan)) else Brush.horizontalGradient(listOf(PanelSoft, Panel)))
        .clickable(enabled = enabled, onClick = onClick), contentAlignment = Alignment.Center) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            Icon(icon, null, tint = if (enabled) Ink else Muted, modifier = Modifier.size(22.dp))
            Text(text, color = if (enabled) Ink else Muted, style = MaterialTheme.typography.labelLarge)
        }
    }
}

@Composable
private fun ScreenTitle(title: String, subtitle: String) {
    Column(Modifier.padding(bottom = 8.dp)) {
        Text(title, style = MaterialTheme.typography.headlineMedium)
        Text(subtitle, color = Muted, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 6.dp))
    }
}

@Composable
private fun Avatar(name: String, extension: Boolean) {
    Box(Modifier.size(44.dp).clip(RoundedCornerShape(15.dp)).background(Cyan.copy(alpha = 0.09f)), contentAlignment = Alignment.Center) {
        if (extension) Icon(Icons.Outlined.SupportAgent, null, tint = Cyan, modifier = Modifier.size(21.dp)) else Text(name.take(1), color = Cyan, fontWeight = FontWeight.Bold)
    }
}

@Composable
private fun EmptyState(icon: ImageVector, title: String, body: String) {
    Column(Modifier.fillMaxWidth().padding(vertical = 52.dp), horizontalAlignment = Alignment.CenterHorizontally) {
        Box(Modifier.size(78.dp).clip(RoundedCornerShape(27.dp)).background(Panel), contentAlignment = Alignment.Center) { Icon(icon, null, tint = Cyan, modifier = Modifier.size(33.dp)) }
        Spacer(Modifier.height(24.dp)); Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        Text(body, color = Muted, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
fun ErrorBanner(error: PhoneError, dismiss: () -> Unit) {
    val key = when (error) { PhoneError.ACCOUNT -> R.string.error_account; PhoneError.REGISTRATION -> R.string.error_registration; PhoneError.NETWORK -> R.string.error_network; PhoneError.CALL -> R.string.error_call; PhoneError.MICROPHONE -> R.string.error_microphone; PhoneError.BUSY -> R.string.error_busy; PhoneError.INVALID_NUMBER -> R.string.error_number; PhoneError.STORAGE -> R.string.error_storage }
    Row(Modifier.fillMaxWidth().padding(horizontal = 24.dp, vertical = 8.dp).clip(RoundedCornerShape(16.dp)).background(Danger.copy(alpha = .10f)).padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(stringResource(key), color = Danger, style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
        IconButton(onClick = dismiss) { Icon(Icons.Outlined.Close, stringResource(R.string.dismiss), tint = Danger) }
    }
}
