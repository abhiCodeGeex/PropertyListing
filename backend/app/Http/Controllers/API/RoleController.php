<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function __construct()
    {
        // only super-admin can manage roles
        // $this->middleware('role:super-admin');
    }

    public function index()
    {
        return Role::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
        ]);

        $role = Role::create(['name' => $request->name]);

        return response()->json($role, 201);
    }

    public function show(Role $role)
    {
        return $role;
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $role->name = $request->name;
        $role->save();

        return response()->json($role);
    }

    public function destroy(Role $role)
    {
        // prevent deleting core super-admin role accidentally
        if ($role->name === 'super-admin') {
            return response()->json(['message' => 'Cannot delete super-admin role'], 422);
        }
        $role->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
