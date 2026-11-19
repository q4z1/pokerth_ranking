<?php

namespace App\Http\Controllers\Admin;

use App\Models\Advert;
use Illuminate\Http\Request;

/** Werbe-/Hinweisblöcke der Webseite verwalten (öffentlich ausgeliefert über AdvertController). */
class AdvertsController extends AdminBaseController
{
  public function adverts(Request $request)
  {
    if ($request->isMethod('GET')) {
      return ['success' => true, 'list' => Advert::orderBy('position', 'ASC')->orderBy('order', 'ASC')->get(['id', 'position', 'content', 'order', 'start', 'end'])];
    }

    $action = $request->input('action');
    if ($action === 'delete') {
      $advert = Advert::find((int) $request->input('id'));
      if (!$advert) return ['success' => false, 'msg' => 'Advert not found.'];
      $advert->delete();
      $this->audit()->log('advert_delete', [], ['id' => $advert->id, 'position' => $advert->position]);
      return ['success' => true, 'msg' => 'Advert deleted.'];
    }

    if ($action === 'create' || $action === 'update') {
      $data = $request->validate([
        'position' => 'required|string|max:32',
        'content'  => 'required|string',
        'order'    => 'required|integer|min:0|max:127',
        'start'    => 'required|date',
        'end'      => 'required|date|after_or_equal:start',
      ]);
      if ($action === 'create') {
        $advert = Advert::create($data);
        $this->audit()->log('advert_create', [], ['id' => $advert->id, 'position' => $advert->position]);
        return ['success' => true, 'msg' => 'Advert created.', 'advert' => $advert];
      }
      $advert = Advert::find((int) $request->input('id'));
      if (!$advert) return ['success' => false, 'msg' => 'Advert not found.'];
      $advert->update($data);
      $this->audit()->log('advert_update', [], ['id' => $advert->id, 'position' => $advert->position]);
      return ['success' => true, 'msg' => 'Advert updated.', 'advert' => $advert];
    }

    return ['success' => false, 'msg' => 'Unknown action.'];
  }
}
