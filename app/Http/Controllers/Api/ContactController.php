<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\Conversation;
use App\Support\AdminAudit;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);

        $query = Contact::query();

        if ($request->boolean('has_conversations')) {
            $query->whereHas('conversations');
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $digits = preg_replace('/\D+/', '', $search);

            $query->where(function ($q) use ($search, $digits) {
                $q->where('phone_number', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('profile_name', 'like', '%'.$search.'%');
                if ($digits !== '') {
                    $q->orWhere('phone_number', 'like', '%'.$digits.'%');
                }
            });
        }

        $sort = $request->query('sort');
        if ($request->boolean('has_conversations') && ! $request->has('sort')) {
            $sort = 'conversation_activity';
        }

        if ($sort === 'conversation_activity') {
            $query->orderByDesc(
                Conversation::query()
                    ->selectRaw('max(last_message_at)')
                    ->whereColumn('contact_id', 'contacts.id')
            );
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $contacts = $query->paginate($perPage);

        return response()->json([
            'data' => ContactResource::collection($contacts->getCollection()),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'phone_number' => 'required|string',
            'name' => 'nullable|string',
            'profile_name' => 'nullable|string',
            'opt_in' => 'nullable|boolean',
        ]);

        $data['created_via'] = 'manual';

        $contact = Contact::create($data);

        AdminAudit::log($request, 'contact.created', $contact, [
            'phone_number' => $contact->phone_number,
        ]);

        return response()->json([
            'message' => 'Contact created successfully',
            'data' => new ContactResource($contact),
        ], 201);
    }

    public function show(int $id)
    {
        $contact = Contact::findOrFail($id);

        return response()->json([
            'data' => new ContactResource($contact),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $contact = Contact::findOrFail($id);

        $data = $request->validate([
            'phone_number' => 'sometimes|string',
            'name' => 'nullable|string',
            'profile_name' => 'nullable|string',
            'opt_in' => 'nullable|boolean',
        ]);

        $contact->update($data);

        AdminAudit::log($request, 'contact.updated', $contact, [
            'phone_number' => $contact->phone_number,
        ]);

        return response()->json([
            'message' => 'Contact updated successfully',
            'data' => new ContactResource($contact),
        ]);
    }

    public function destroy(Request $request, int $id)
    {
        $contact = Contact::findOrFail($id);

        AdminAudit::log($request, 'contact.deleted', $contact, [
            'phone_number' => $contact->phone_number,
        ]);

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully',
        ]);
    }
}
