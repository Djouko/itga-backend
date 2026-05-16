<?php

namespace App\Http\Controllers;
use App\Helpers\AdminPassword;
use App\Models\Admin;
use App\Models\GlobalFunction;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class LoginController extends Controller
{

    function login()
    {
        $setting = Setting::first();
        if ($setting) {
            Session::put('app_name', $setting->app_name);
        }
        if (Session::get('user_name')) {
            return redirect('/index');
        }
        return view('login');
    }

    public function checklogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_name' => 'required|string',
            'user_password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }

        $data = Admin::where('user_name', $request->user_name)->first();

        if ($data && AdminPassword::matches($data, (string) $request->user_password)) {
            if (AdminPassword::rehashIfNeeded($data, (string) $request->user_password)) {
                $data->save();
            }

            $request->session()->put('user_name', $data->user_name);
            $request->session()->put('user_type', $data->user_type);

            return GlobalFunction::sendDataResponse(true, 'Login Successfully.', $data);
        }

        return GlobalFunction::sendSimpleResponse(false, 'Wrong credentials.');
    }

    public function forgotPasswordForm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_key' => 'required|string',
            'new_password' => 'required|string|min:8',
            'confirm_password' => 'required|string|same:new_password',
        ]);

        if ($validator->fails()) {
            return GlobalFunction::sendSimpleResponse(false, $validator->errors()->first());
        }

        $resetKey = env('ADMIN_PASSWORD_RESET_KEY', '');

        if (empty($resetKey)) {
            return GlobalFunction::sendSimpleResponse(false, 'Admin password reset is not configured.');
        }

        if (hash_equals((string) $resetKey, (string) $request->reset_key)) {

            $admin = Admin::where('user_name', 'admin')->first();

            if (!$admin) {
                return GlobalFunction::sendSimpleResponse(false, 'Admin user not found.');
            }

            AdminPassword::set($admin, (string) $request->new_password);
            $admin->save();

            return GlobalFunction::sendSimpleResponse(true, 'Password updated successfully.');
        } else {
            return GlobalFunction::sendSimpleResponse(false, 'Wrong credentials.');
        }
    }



    function logout()
    {

        session()->pull('user_name');
        session()->pull('user_type');
        return  redirect(url('/'));
    }
}
