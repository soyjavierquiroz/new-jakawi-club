<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Welcome', [
            'heading' => 'JAKAWI Launch Engine',
            'message' => 'Próximamente en jakawi.club',
        ]);
    }
}
