<?php

namespace Tests\Feature;

use Tests\TestCase;

class DownloadPageTest extends TestCase
{
    public function test_download_page_lists_installers(): void
    {
        $this->get('/download')->assertOk()
            ->assertSee('Download APK')
            ->assertSee('Download for Windows')
            ->assertSee(route('download.qr'), false);
    }

    public function test_qr_is_an_svg_of_the_download_page_only(): void
    {
        $res = $this->get('/download/qr.svg')->assertOk();
        $this->assertStringContainsString('image/svg+xml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $res->getContent());
    }
}
