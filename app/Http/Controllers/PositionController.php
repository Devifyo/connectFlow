<?php

namespace App\Http\Controllers;

use App\Models\Position;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function index()
    {
        $positions = Position::withCount('users')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'description' => $p->description,
                'is_active' => $p->is_active,
                'sort_order' => $p->sort_order,
                'users_count' => $p->users_count,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);

        return response()->json(['positions' => $positions]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $exists = Position::where('title', $request->title)->exists();
        if ($exists) {
            return response()->json(['message' => 'A position with this title already exists.'], 422);
        }

        $maxOrder = Position::max('sort_order') ?? 0;

        $position = Position::create([
            'tenant_id' => auth()->user()->tenant_id,
            'title' => $request->title,
            'description' => $request->description,
            'is_active' => true,
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json([
            'status' => 'success',
            'position' => [
                'id' => $position->id,
                'title' => $position->title,
                'description' => $position->description,
                'is_active' => $position->is_active,
                'users_count' => 0,
                'created_at' => $position->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $position = Position::findOrFail($id);

        if ($request->has('title') && $request->title !== $position->title) {
            $exists = Position::where('title', $request->title)->where('id', '!=', $id)->exists();
            if ($exists) {
                return response()->json(['message' => 'A position with this title already exists.'], 422);
            }
        }

        $fields = ['title', 'description', 'is_active'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $position->$field = $request->$field;
            }
        }
        $position->save();

        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        $position = Position::withCount('users')->findOrFail($id);

        if ($position->users_count > 0) {
            return response()->json([
                'message' => "Cannot delete this position — {$position->users_count} member(s) are assigned to it. Reassign them first.",
            ], 422);
        }

        $position->delete();

        return response()->json(['status' => 'success']);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:positions,id',
        ]);

        foreach ($request->ids as $index => $id) {
            Position::where('id', $id)->update(['sort_order' => $index + 1]);
        }

        return response()->json(['status' => 'success']);
    }
}
