<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RoleController extends Controller
{
   public function index()
    {
        $user = Auth::user();

        // If logged in user has role 'super admin', show all roles
        if ($user && $user->role && strtolower($user->role->name) === 'super-admin') {
            return Role::all();
        }

        // Otherwise, return all roles except 'Super Admin'
        return Role::where('name', '!=', 'super-admin')->get();
    }
    public function store(Request $request){
        $data = $request->validate(['name'=>'required|string|unique:roles,name']);
        $role = Role::create($data);
        return response()->json($role,201);
    }

}
