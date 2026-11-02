<?php

namespace App\Http\Controllers;

use App\Services\OpenWa\OpenWaClient;
use App\Services\OpenWa\OpenWaException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function show(OpenWaClient $client): View
    {
        $session = $qr = $error = null;
        try {
            $session = $client->session();
            if (($session['status'] ?? '') === 'qr_ready') {
                $qr = $client->qr()['qrCode'] ?? null;
            }
        } catch (OpenWaException $e) {
            $error = $e->getMessage();
        }

        return view('session', compact('session', 'qr', 'error'));
    }

    public function start(OpenWaClient $client): RedirectResponse
    {
        try {
            $client->start();
        } catch (OpenWaException $e) {
            return back()->withErrors(['session' => $e->getMessage()]);
        }

        return back()->with('status', 'Start requested. Refresh in a few seconds to see the QR code.');
    }
}
