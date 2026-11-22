<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Public\HeroResource;
use App\Models\HeroContent;
use App\Models\HeroSetting;
use App\Models\MetaPixelSetting;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/home : content of the website home page, in one call.
 *
 * - display: every action of Website > Hero settings, {status, value} by action name.
 *            The website decides the hero from these (see the frontend's resolveHeroMode()).
 * - hero.slides: the slides marked "Show" on Website > Hero content, in slider order.
 *            Always sent, so the website can fall back correctly.
 * - settings.meta_pixel_id: from System > Meta Pixel (null when it is off or not set).
 * - settings.whatsapp_*: not managed from the admin panel yet, always null for now.
 */
class HomeController extends Controller
{
    public function index(): JsonResponse
    {
        $slides = HeroContent::active()->ordered()->get();

        return response()->json([
            'data' => [
                'display' => HeroSetting::current(),
                'hero' => [
                    'slides' => HeroResource::collection($slides)->resolve(),
                ],
                'settings' => [
                    'meta_pixel_id' => MetaPixelSetting::activeId(),
                    'whatsapp_number' => null,
                    'whatsapp_message' => null,
                ],
            ]
        ]);
    }
}
