<?php

namespace App\Http\Controllers\Api;

use App\Enums\PopupKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PopupResource;
use App\Models\Popup;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/popups : the popups that are on, for every page of the website.
 *
 * modals / corners, each in their order (first match wins). Each has pages, delay_seconds
 * and frequency; the website decides on its side which one fits the current page and
 * whether this visitor has seen it already. A welcome popup = a modal with frequency "once".
 */
class PopupController extends Controller
{
    public function index(): JsonResponse
    {
        $popups = Popup::active()->with('setting')->ordered()->get()->groupBy(fn (Popup $p) => $p->kind->value);
        $list = fn (PopupKind $kind) => PopupResource::collection($popups->get($kind->value, collect()))->resolve();

        return response()->json(['data' => [
            'modals' => $list(PopupKind::Modal),
            'corners' => $list(PopupKind::Corner),
        ]]);
    }
}
