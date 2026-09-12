<?php

namespace Tests\Feature;

use App\Support\RobotsPolicy;
use Tests\TestCase;

class RobotsPolicyTest extends TestCase
{
    public function test_checked_in_robots_file_is_deterministically_generated(): void
    {
        $this->assertSame(
            RobotsPolicy::render((array) config('seo.crawler_policy')),
            file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_generator_writes_the_complete_file_without_leaving_a_temporary_file(): void
    {
        $this->artisan('seo:robots')->assertSuccessful();

        $this->assertFileDoesNotExist(public_path('robots.txt.tmp'));
        $this->assertSame(
            RobotsPolicy::render((array) config('seo.crawler_policy')),
            file_get_contents(public_path('robots.txt'))
        );
    }

    public function test_search_and_training_policies_are_explicit_and_independent(): void
    {
        $policy = (array) config('seo.crawler_policy');
        $policy['search_discovery']['allow'] = true;
        $policy['model_training']['allow'] = false;
        $robots = RobotsPolicy::render($policy);

        foreach (['OAI-SearchBot', 'PerplexityBot', 'Googlebot', 'Bingbot'] as $agent) {
            $group = $this->group($robots, $agent);
            $this->assertStringContainsString("Allow: /\n", $group);
            foreach ($policy['private_paths'] as $privatePath) {
                $this->assertStringContainsString('Disallow: '.$privatePath, $group, $agent.' can crawl '.$privatePath);
            }
        }

        foreach (['GPTBot', 'Google-Extended'] as $agent) {
            $this->assertSame("User-agent: {$agent}\nDisallow: /", $this->group($robots, $agent));
        }
    }

    public function test_an_incomplete_policy_fails_closed_without_overwriting_robots(): void
    {
        $before = file_get_contents(public_path('robots.txt'));
        config(['seo.crawler_policy.private_paths' => []]);

        $this->artisan('seo:robots')->assertFailed();

        $this->assertSame($before, file_get_contents(public_path('robots.txt')));
    }

    public function test_one_malformed_private_path_rejects_the_entire_policy(): void
    {
        $policy = (array) config('seo.crawler_policy');
        $policy['private_paths'] = ['/account', 'admin'];

        $this->expectException(\InvalidArgumentException::class);

        RobotsPolicy::render($policy);
    }

    private function group(string $robots, string $agent): string
    {
        preg_match('~User-agent: '.preg_quote($agent, '~')."\n.*?(?=\n\n|\z)~s", $robots, $match);

        return $match[0] ?? '';
    }
}
