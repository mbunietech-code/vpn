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
            ->assertSee('Download for macOS')
            ->assertSee('Mbunie-VPN-1.0.17-macos.dmg', false)
            ->assertSee(route('download.qr'), false)
            ->assertSee(route('download.qr.android'), false)
            ->assertSee('Coming soon');
    }

    public function test_android_qr_is_an_svg(): void
    {
        $res = $this->get('/download/android-qr.svg')->assertOk();
        $this->assertStringContainsString('<svg', $res->getContent());
    }

    public function test_qr_is_an_svg_of_the_download_page_only(): void
    {
        $res = $this->get('/download/qr.svg')->assertOk();
        $this->assertStringContainsString('image/svg+xml', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('<svg', $res->getContent());
    }
}
