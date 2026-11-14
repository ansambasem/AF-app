<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class MobileAppDownloadController extends Controller
{
    public function index(): Response
    {
        $release = $this->release();

        return Inertia::render('Settings/MobileApp', [
            'release' => $release === null ? null : [
                'version' => $release['version'],
                'sizeMb' => round($release['size'] / 1048576, 1),
                'sha256' => $release['sha256'],
                'downloadUrl' => route('settings.mobile-app.download'),
            ],
        ]);
    }

    public function download(): RedirectResponse
    {
        $release = $this->release();
        abort_if($release === null, 404);

        return redirect()->away($release['download_url'], 302, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /** @return array{version: string, size: int, sha256: string, download_url: string}|null */
    private function release(): ?array
    {
        $metadata = $this->directory().'/release.json';
        if (! is_file($metadata)) {
            return null;
        }
        $release = json_decode(file_get_contents($metadata), true);
        if (! is_array($release) || ($release['type'] ?? null) !== 'release'
            || ! is_string($release['version'] ?? null) || ! preg_match('/^\d+\.\d+\.\d+$/', $release['version'])
            || ! is_string($release['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $release['sha256'])
            || ! is_int($release['size'] ?? null) || $release['size'] <= 0
            || ! is_string($release['download_url'] ?? null)
            || ($release['download_url'] !== 'https://github.com/MohammedM98/AF-powercollect/releases/download/android-v'.$release['version'].'/powercollect.apk'
                && ! preg_match('~\Ahttps://raw\.githubusercontent\.com/MohammedM98/AF-powercollect/[a-f0-9]{40}/resources/mobile/powercollect\.apk\z~', $release['download_url']))) {
            return null;
        }

        return ['version' => $release['version'], 'size' => $release['size'], 'sha256' => $release['sha256'], 'download_url' => $release['download_url']];
    }

    private function directory(): string
    {
        return config('powercollect.mobile_app.directory');
    }
}
