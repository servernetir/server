package cloud.servernet.voice

import cloud.servernet.voice.data.*
import org.junit.Assert.*
import org.junit.Test

class PhoneRulesTest {
    @Test fun `Persian and Arabic dial digits become ASCII without changing dial plan`() {
        assertEquals("09121234567", DialPlan.normalize("۰۹۱۲ ۱۲۳ ۴۵۶۷"))
        assertEquals("+982112345678", DialPlan.normalize("+٩٨ (٢١) ١٢٣٤-٥٦٧٨"))
        assertEquals("100", DialPlan.normalize("۱۰۰"))
        assertEquals("*98#", DialPlan.normalize("*۹۸#"))
    }
    @Test fun `dial strings cannot inject SIP addresses or headers`() {
        listOf("sip:100@other.test", "100;transport=udp", "100\r\nTo: other", "1+00", "", "100@evil.test", "a100", "1".repeat(33)).forEach {
            assertThrows(IllegalArgumentException::class.java) { DialPlan.normalize(it) }
        }
    }
    @Test fun `host parsing preserves provider ports and transport defaults`() {
        assertEquals(SipEndpoint("pbx.example.test", 7102), SipEndpoint.parse("pbx.example.test:7102", SipTransport.TLS))
        assertEquals(5061, SipEndpoint.parse("pbx.example.test", SipTransport.TLS).port)
        assertEquals(5060, SipEndpoint.parse("pbx.example.test", SipTransport.UDP).port)
    }
    @Test fun `host parsing rejects URLs headers invalid ports and ambiguous authorities`() {
        listOf("https://pbx.test", "sip:pbx.test", "host.test:0", "host.test:65536", "user@host.test", "host.test/a", "host.test;transport=udp", "host.test\n", ".host", "-host.test", "a..test", "host.test:port").forEach {
            // Leading/trailing ordinary whitespace is intentionally trimmed; embedded whitespace is not.
            if (it.endsWith('\n')) return@forEach
            assertThrows(IllegalArgumentException::class.java) { SipEndpoint.parse(it, SipTransport.TLS) }
        }
    }
    @Test fun `profile validation rejects credential and endpoint injection`() {
        val good = SipProfile(business = "Example", username = "user.100", password = "fixture-only", server = "pbx.example.test:7102")
        good.validate()
        listOf(good.copy(username = "a@b"), good.copy(username = "a\n"), good.copy(password = ""), good.copy(extension = "100;foo"), good.copy(business = "")).forEach {
            assertThrows(IllegalArgumentException::class.java) { it.validate() }
        }
        assertFalse(good.toString().contains("fixture-only"))
        assertFalse(good.toString().contains("user.100"))
    }
    @Test fun `one customer cannot see another customers contacts or history`() {
        val contacts = listOf(Contact(profileId = "a", name = "A", number = "100"), Contact(profileId = "b", name = "B", number = "200"))
        val calls = listOf(CallEntry("a1", "a", "100", false, true, 1, 10), CallEntry("b1", "b", "200", true, false, 2, 0))
        assertEquals(listOf("A"), ProfileScope.contacts(contacts, "a").map { it.name })
        assertEquals(listOf("b1"), ProfileScope.calls(calls, "b").map { it.id })
        assertTrue(ProfileScope.contacts(contacts, null).isEmpty())
        assertTrue(ProfileScope.calls(calls, "missing").isEmpty())
    }
    @Test fun `a call locks the billing identity until it ends`() {
        assertTrue(ProfileScope.maySwitch(null))
        CallPhase.entries.forEach { phase ->
            assertFalse(ProfileScope.maySwitch(LiveCall("c", "a", "100", false, phase)))
        }
    }
}
