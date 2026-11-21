<?php

declare(strict_types=1);

namespace Drupal\Tests\scolta_chat_poc\Unit;

use PHPUnit\Framework\Attributes\Group;
use Drupal\Tests\UnitTestCase;
use Drupal\scolta_chat_poc\Chat\ResultsMarkup;
use Drupal\scolta_chat_poc\Chat\TurnPayload;

/**
 * Tests the results block appended after the Xss filter.
 */
#[Group('scolta_chat_poc')]
final class ResultsMarkupTest extends UnitTestCase {

  /**
   * Titles, URLs, excerpts and the query carrying markup come out escaped.
   */
  public function testEscapesEveryValue(): void {
    $results = [[
      'n' => 1,
      'title' => '<script>alert("t")</script><b>Title</b>',
      'url' => '/page?a=1&b="><img src=x onerror=alert(1)>',
      'excerpt' => '<img src=x onerror=alert(2)> excerpt',
    ]];
    $html = ResultsMarkup::render('<i>q</i>', $results, [1], 'Site <b>');

    $this->assertStringNotContainsString('<script', $html);
    $this->assertStringNotContainsString('<img', $html);
    $this->assertStringNotContainsString('<b>', $html);
    $this->assertStringNotContainsString('<i>q', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(&quot;t&quot;)&lt;/script&gt;&lt;b&gt;Title&lt;/b&gt;', $html);
    $this->assertStringContainsString('href="/page?a=1&amp;b=&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $html);
    $this->assertStringContainsString('Search results for: &lt;i&gt;q&lt;/i&gt;', $html);
    $this->assertStringContainsString('From pages on Site &lt;b&gt;', $html);
    $this->assertStringContainsString('(cited)', $html);
  }

  /**
   * The results start collapsed behind a one line summary with the counts.
   */
  public function testResultsAreCollapsed(): void {
    $results = [
      ['n' => 1, 'title' => 'a', 'url' => '/a', 'excerpt' => ''],
      ['n' => 2, 'title' => 'b', 'url' => '/b', 'excerpt' => ''],
      ['n' => 3, 'title' => 'c', 'url' => '/c', 'excerpt' => ''],
    ];
    $html = ResultsMarkup::render('q', $results, [1, 3], 'S');
    $this->assertStringStartsWith('<details class="scolta-chat-results"', $html);
    $this->assertStringNotContainsString('<details class="scolta-chat-results" open', $html);
    $this->assertStringContainsString('Sources: 2 cited, 3 found</summary>', $html);
    $this->assertStringEndsWith('</details>', $html);

    $this->assertStringContainsString('Sources: 3 found, none cited</summary>', ResultsMarkup::render('q', $results, [], 'S'));
    $this->assertStringContainsString('Sources: no pages matched</summary>', ResultsMarkup::render('q', [], [], 'S'));
  }

  /**
   * A dangerous scheme never reaches an href, even if the payload let it by.
   */
  public function testDangerousSchemeIsStripped(): void {
    $html = ResultsMarkup::render('q', [['n' => 1, 'title' => 't', 'url' => 'javascript:alert(1)', 'excerpt' => '']], [], 'S');
    $this->assertStringNotContainsString('javascript:', $html);
  }

  /**
   * The payload keeps only links on the site itself.
   */
  public function testPayloadKeepsOnlySiteLinks(): void {
    $payload = TurnPayload::fromRequest([
      'query' => 'q',
      'results' => [
        ['title' => 'ok relative', 'url' => '/a', 'excerpt' => ''],
        ['title' => 'ok absolute', 'url' => 'https://example.org/b', 'excerpt' => ''],
        ['title' => 'other host', 'url' => 'https://evil.test/c', 'excerpt' => ''],
        ['title' => 'script', 'url' => 'javascript:alert(1)', 'excerpt' => ''],
        ['title' => 'protocol relative', 'url' => '//evil.test/d', 'excerpt' => ''],
      ],
      'context' => str_repeat('c', 50000),
    ], 'fallback', 1000, 'example.org');

    $this->assertSame(['/a', 'https://example.org/b'], array_column($payload->results, 'url'));
    $this->assertSame([1, 2], array_column($payload->results, 'n'));
    $this->assertSame(1500, mb_strlen($payload->context), 'The context is cut to one and a half times the budget.');
  }

  /**
   * A result is cited by its linked URL or by its marker.
   */
  public function testCitedNumbers(): void {
    $results = [
      ['n' => 1, 'url' => 'https://example.org/one'],
      ['n' => 2, 'url' => 'https://example.org/two'],
      ['n' => 3, 'url' => 'https://example.org/three'],
    ];
    $answer = 'A fact [[1]](https://example.org/one). Another from [the page](/three).';
    $this->assertSame([1, 3], ResultsMarkup::citedNumbers($answer, $results));
  }

}
