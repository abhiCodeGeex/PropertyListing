<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private function userRules(?User $user = null, bool $isCreate = true): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                $user
                    ? Rule::unique('users', 'email')->ignore($user->id)
                    : 'unique:users,email',
            ],
            'password' => $isCreate ? 'required|min:6' : 'nullable|min:6',
            'roles' => 'required|array|min:1',
            'roles.*' => 'required|exists:roles,id',
        ];
    }

    public function __construct()
    {
        // super-admin only for user role management (adjust as needed)
        // $this->middleware('role:super-admin')->except(['me']);
    }

    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $query = User::with('roles');

        $roles = $request->roles;

        if ($roles) {
            if (is_string($roles)) {
                $roles = explode(',', $roles);
            }

            $query->whereHas('roles', function ($q) use ($roles) {
                $q->whereIn('name', $roles);
            });
        }

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function store(Request $request)
    {
        $request->validate($this->userRules(), [], [
            'roles' => 'roles',
            'roles.*' => 'role',
        ]);

        $user = User::create([
            'name' => trim($request->name),
            'email' => trim($request->email),
            'password' => Hash::make($request->password),
        ]);
        foreach ($request->roles as $role) {
            $role = Role::find($role);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        return response()->json($user->load('roles'), 201);
    }

    public function show(User $user)
    {
        return $user->load('roles');
    }

    public function update(Request $request, User $user)
    {
        $request->validate($this->userRules($user, false), [], [
            'roles' => 'roles',
            'roles.*' => 'role',
        ]);

        if ($request->filled('name')) {
            $user->name = trim($request->name);
        }

        if ($request->filled('email')) {
            $user->email = trim($request->email);
        }

        if ($request->filled('password')) {
            $user->password = Hash::make($request->password);
        }

        $user->save();
        $user->roles()->detach();
        foreach ($request->roles as $role) {
            $role = Role::find($role);
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
        }

        return $user->load('roles');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'Deleted']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(null, 200);
        }

        // Eager load roles & profile
        $user->load(['roles', 'profile']);

        return response()->json([
            'user' => $user,
            'roles' => $user->roles->pluck('name'),
            'profile' => $user->profile,
        ], 200);
    }

    public function assignRole(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid role selection',
                'errors' => $validator->errors(),
            ], 422);
        }

        $roleName = $request->input('role');

        try {
            $user->syncRoles([$roleName]);

            return response()->json([
                'message' => 'Role assigned successfully',
                'role' => $roleName,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to assign role',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function myNotifications(Request $request)
    {
        $perPage = max(1, min((int) $request->integer('per_page', 10), 50));

        return auth()->user()
            ->notifications()
            ->latest()
            ->paginate($perPage);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = Notification::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if (! $notification->is_read) {
            $notification->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }
}
