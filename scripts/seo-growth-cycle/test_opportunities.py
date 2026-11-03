import unittest

from opportunities import metrics, opportunities, period


def pair(query="سرور مجازی ساعتی", page="https://servernet.cloud/vps/hourly", **values):
    return dict(query=query, page=page, clicks=0, impressions=200, position=15, **values)


class OpportunityTests(unittest.TestCase):
    def test_ranking_problem_is_not_misdiagnosed_as_a_title_problem(self):
        row = pair()
        row["position"] = 65
        found = opportunities([row])[0]
        self.assertEqual("ranking_first", found["action"])
        self.assertNotIn("expected_clicks", found)

    def test_actual_clicks_determine_ctr_without_rounding_or_percent_confusion(self):
        row = pair()
        row.update(clicks=20, ctr=0.0001)
        found = opportunities([row])[0]
        self.assertEqual(0.1, found["ctr"])
        self.assertEqual("protect_snippet_improve_relevance", found["action"])

    def test_brand_private_queries_and_private_or_external_urls_are_excluded(self):
        for row in [
            pair("server net"), pair("سرورنت"), pair("هاست example@example.com"),
            pair("سرور 09123456789"),
            pair(page="https://console.servernet.cloud/vps/hourly"),
            pair(page="https://servernet.cloud.evil.invalid/vps/hourly"),
            pair(page="https://servernet.cloud/account"),
            pair(page="https://servernet.cloud/vps/hourly?token=private"),
            pair(page="https://servernet.cloud/vps/hourly#private"),
        ]:
            self.assertEqual([], opportunities([row]), row)

    def test_three_languages_and_same_query_multiple_urls_need_human_review(self):
        data = [
            pair("hourly vps", "https://servernet.cloud/en/vps/hourly"),
            pair("saatlik vps", "https://servernet.cloud/tr/vps/hourly"),
            pair("سرور گرافیکی", "https://servernet.cloud/gpu"),
            pair("سرور گرافیکی", "https://servernet.cloud/vps/gpu"),
        ]
        found = opportunities(data)
        self.assertEqual(4, len(found))
        self.assertEqual(2, len(next(x for x in found if x["query"] == "سرور گرافیکی")["competing_urls"]))
        self.assertTrue(all("requires" in x["ctr_diagnosis"] for x in found))

    def test_property_ctr_and_position_are_impression_weighted(self):
        result = metrics([dict(clicks=1, impressions=10, position=2), dict(clicks=9, impressions=90, position=12)])
        self.assertEqual(0.1, result["ctr"])
        self.assertEqual(11, result["position"])
        self.assertIsNone(metrics([])["ctr"])

    def test_date_boundaries_are_inclusive_and_incomplete_recent_days_can_be_excluded(self):
        data = [dict(date=d, clicks=1, impressions=10, position=4) for d in ["2026-08-02", "2026-08-03", "2026-10-31", "2026-11-01"]]
        self.assertEqual(2, period(data, "2026-08-03", "2026-10-31")["clicks"])
        with self.assertRaises(ValueError):
            period(data, "2026-10-31", "2026-08-03")

    def test_invalid_and_tiny_rows_do_not_become_opportunities(self):
        for value in [float("nan"), float("inf"), -1]:
            row = pair()
            row["position"] = value
            self.assertEqual([], opportunities([row]))
        row = pair()
        row.update(clicks=201)
        self.assertEqual([], opportunities([row]))
        self.assertEqual([], opportunities([pair()], minimum_impressions=201))


if __name__ == "__main__":
    unittest.main()
