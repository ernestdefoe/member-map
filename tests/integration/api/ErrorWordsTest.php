<?php

namespace ErnestDefoe\MemberMap\Tests\integration\api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Every error code the API can answer with has words in the forum's language file (else the member sees a generic error). */
class ErrorWordsTest extends TestCase
{
    #[Test]
    public function every_error_code_has_a_sentence()
    {
        preg_match_all("/->status\\('(member_map_[a-z_]+)'/", (string) file_get_contents(__DIR__.'/../../../extend.php'), $codes);
        $words = Yaml::parseFile(__DIR__.'/../../../locale/en.yml')['ernestdefoe-member-map']['forum']['errors'];

        $this->assertNotEmpty($codes[1]);
        foreach ($codes[1] as $code) {
            $this->assertArrayHasKey($code, $words, "No sentence for $code");
        }
    }
}
