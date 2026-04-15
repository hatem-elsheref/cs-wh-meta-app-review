<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $query = Contact::query();

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone_number', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('profile_name', 'like', "%{$search}%");
            });
        }

        $contacts = $query->orderBy('created_at', 'desc')->paginate(20);

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

        $contact = Contact::create($data);

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

        return response()->json([
            'message' => 'Contact updated successfully',
            'data' => new ContactResource($contact),
        ]);
    }

    public function destroy(int $id)
    {
        $contact = Contact::findOrFail($id);
        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully',
        ]);
    }
}
