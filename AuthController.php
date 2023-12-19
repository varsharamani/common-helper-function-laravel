<?php

namespace App\Http\Controllers\Auth;

use Mail;
use Exception;
use Carbon\Carbon;
use App\Models\User;
use Aws\S3\S3Client;
use App\Models\Device;
use App\Models\Review;
use Mockery\Undefined;
use App\Models\Company;
use Illuminate\Support\Str;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\JobPreference;
use App\Services\CommonService;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redirect;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\NotificationResource;
use App\Notifications\SendEmailNotification;
use App\Http\Resources\JobPreferenceResource;
use App\Http\Resources\JobPreferenceCollection;

class AuthController extends Controller
{
    protected $user;
    public function __construct()
    {
        $this->user = new User();
    }

    public function me(Request $request)
    {
        try {
            if (auth()->user()) {

                auth()->user()->user_reviews = Review::where('user_id', auth()->user()->id)->get();
                return CommonService::successResp('', new UserResource(auth()->user()), 201);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            DB::rollBack();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required',
            'password' => 'required',
            'device_id' => 'nullable',
            'fcm_token' => 'nullable'
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        return $this->loginUser('email password', $data);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|min:1|max:255',
            'email' => 'required|email|email:filter,rfc,dns|min:1|max:255|unique:users,email,NULL,id,deleted_at,NULL',
            'password' => 'required|min:8|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{6,}$/',
            'role' => 'required|min:1|max:2',
            'mobile_no' => 'required|string|min:10|max:15',
            'gender' => 'sometimes|nullable',
            'device_id' => 'nullable',
            'fcm_token' => 'nullable',
            'address' => 'sometimes|nullable|string|max:255|min:1',
            'zipcode' => 'sometimes|required|numeric|digits_between:2,8',
            'profile_photo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
            'w9_document' => 'required|mimes:jpg,jpeg,png,svg,webp,pdf,docx,doc',
            'invite_code' => 'nullable',
            'birth_date' => 'required|date_format:Y-m-d|before:-14 years',
            'bio' => 'nullable|min:1|max:200',
            'login_by' => 'nullable',
            'provider_info' => 'nullable',
            'company' => 'sometimes|array',
            'company.name' => 'sometimes|required|string|min:1|max:255',
            'company.address' => 'sometimes|required|string|min:1|max:255',
            'company.zipcode' => 'sometimes|required|numeric|digits_between:2,8',
            'job_preference' => 'sometimes|required'
        ], [
            'mobile_no.min' => "The mobile no field must be at least 10 digits",
            'mobile_no.max' => "The mobile no field must not be greater than 15 digits",
            'password.regex' => "The password must have at least one uppercase, lowercase, numeric and special character.",
            'zipcode.digits_between' => 'The zipcode field must be between 2 to 8 digits maximum',
            'birth_date.before' => 'The birth date field must be a date before 14 years.',
            'company.name.required' => 'The company name field is required',
            'company.address.required' => 'The company address field is required',
            'company.zipcode.required' => 'The company zipcode field is required',
            'company.zipcode.digits_between' => 'The company zipcode field must be between 2 to 8 digits maximum'

        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }
        $data = $validator->getData();

        if (strtoupper($data['role']) === 'C') {
            $validator = Validator::make($request->all(), [
                'zipcode' => 'sometimes|required|numeric|digits_between:2,8',
                'job_preference' => 'sometimes|required'
            ], [
                'zipcode.digits_between' => 'The zipcode field must be between 2 to 8 digits maximum'
            ]);
        }

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }


        try {
            DB::beginTransaction();
            $profile_photo = $w9_document = null;
            if ($request->file('profile_photo')) {
                $profile_photo = Storage::disk('spaces')->put(config('app.image_path'), $request->file('profile_photo'), 'private');
            }

            if ($request->file('w9_document')) {
                $w9_document = Storage::disk('spaces')->put(config('app.document_path'), $request->file('w9_document'), 'private');
            }

            $jobPreference = null;
            if (strtoupper($data['role']) === 'C') {
                $status = 'A';

                if (!is_array($data['job_preference'])) {
                    $data['job_preference'] = json_decode($data['job_preference'], true);
                }
                if (!empty($data['job_preference'])) {
                    $jobPreference = implode(',', $data['job_preference']);
                }
            } else if (strtoupper($data['role']) === 'M') {
                $status = 'P';
                if (!empty($data['invite_code'])) {
                    if ($data['invite_code'] === 'SHOWTIME23') {
                        $status = 'A';
                    }
                }
            }

            $user = User::create($request->only('full_name', 'email', 'password', 'role', 'mobile_no', 'gender', 'address', 'zipcode', 'invite_code', 'birth_date', 'bio', 'login_by', 'provider_info') + [
                'profile_photo' => $profile_photo,
                'w9_document' => $w9_document,
                'job_preference' => $jobPreference,
                'status' => $status
            ]);


            if ($user) {
                if (!empty($data['device_id'])  && !empty($data['fcm_token'] !== '')) {
                    Device::updateOrCreate(['device_id' => $data['device_id'], 'user_id' => $user->id], [
                        'device_id' => $data['device_id'],
                        'user_id' => $user->id,
                        'fcm_token' => $data['fcm_token']
                    ]);
                }
            }

            $company = array();
            if ($user) {
                if (strtoupper($data['role']) === 'M') {
                    if ($request->company['name'] !== '' && $request->company['address'] !== '') {
                        $company = new Company();
                        $company->user_id = $user->id;
                        $company->name = $data['company']['name'];
                        $company->address = $data['company']['address'];
                        $company->zipcode = $data['company']['zipcode'];
                        $company->save();
                    }
                }
            }


            if (strtoupper($data['role']) === 'M') {
                $user = User::where('id', '=', $user->id)->with(['company'])->first();
            }
            if (!empty($data['device_id']) && !empty($data['fcm_token'])) {
                $user->device_id = $data['device_id'];
                $user->fcm_token = $data['fcm_token'];
            }
            // return CommonService::successResp('You have been successfully registered ..', new UserResource($user), '', 201);
        } catch (Exception $e) {
            DB::rollBack();
            return CommonService::errorResp('Something went wrong!', 500);
        }
        DB::commit();
        return $this->loginUser('email password', $data);
    }



    public function redirectToFacebook($provider)
    {
        try {
            config()->set('services.facebook.redirect', config('app.contractor_url') . 'auth/facebook/callback');
            $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();

            return response()->json([
                'url' => $url,
            ]);
        } catch (Exception $e) {
            return CommonService::errorResp('Provider not found', 422);
        }
    }

    public function handleFacebookCallback($provider)
    {
        $user = Socialite::driver($provider)->stateless()->user();
        request()->merge(['email' => $user->email]);


        return $this->loginUser('facebook', $user);
    }

    public function loginUser($login_type, $data = null)
    {
        $user = User::whereEmail(request()->email)->first();
        if (empty($user)) {
            return CommonService::errorResp('Oops! Email or password credentials are invalid.', 401);
        }

        // if ($login_type === 'facebook') {
        //     $user->update(['login_by' => 'facebook', 'provider_info' => json_encode($data)]);
        // } else {
        //     $user->update(['login_by' => 'manual', 'provider_info' => null]);
        // }

        if (!request()->role) {
            return CommonService::errorResp('Unauthorized!, Please provide the correct credentials', 401);
        }

        if (!empty($user->status) && $user->status === 'D' || empty($user)) {
            return CommonService::errorResp('This account is decline. Please contact administration', 401);
        }
        // else if (!empty($user->status) && $user->status === 'P' || empty($user)) {
        //     return CommonService::errorResp('Check your email to activate account', 401);
        // }
        // echo "<PRE>";
        // print_r($data);
        // die;

        if (Auth::attempt(['email' => request()->email, 'password' => request()->password])) {

            $user = Auth::user();

            if (request()->role !== $user->role) {
                return CommonService::errorResp('Unauthorized!, Please provide the correct credentials', 401);
            }

            if ($user) {
                if (!empty($data['device_id']) && !empty($data['fcm_token'] !== '')) {
                    Device::updateOrCreate(['device_id' => $data['device_id'], 'user_id' => $user->id], [
                        'device_id' => $data['device_id'],
                        'user_id' => $user->id,
                        'fcm_token' => $data['fcm_token']
                    ]);
                    $user->device_id = $data['device_id'];
                    $user->fcm_token = $data['fcm_token'];
                }
            }



            $user->access_token =  $user->createToken('api_auth_token')->plainTextToken;

            if ($user->id) {
                $reviewData = null;
                if ($user->role === 'C') {
                    $user->rating =  Review::where('user_id', '=', $user->id)->pluck('rating')->avg();
                    $user->user_reviews = Review::where('user_id', $user->id)->get();
                }
            }
            // dd('hii');
            return CommonService::successResp('You have been successfully login..', new UserResource($user), 201);
        }

        // $finduser = User::where(['social_id' => $data->id, 'social_type' => 'facebook'])->first();

        // $newUser = User::updateOrCreate(['email' => $user->email], [
        //     'name' => $user->name,
        //     'social_id' => $user->id,
        //     'social_type' => 'facebook',
        //     // 'password' => encrypt('123456dummy')
        // ]);


        // $validator = Validator::make($request->all(), [
        //     'email' => 'required|email',
        //     'password' => 'required',
        // ]);

        // if ($validator->fails()) {

        //     return Response(['message' => $validator->errors()], 401);
        // }

        // if (Auth::attempt(request()->all())) {

        //     $user = Auth::user();

        //     $success =  $user->createToken('MyApp')->plainTextToken;

        //     return Response(['token' => $success], 200);
        // }
        return CommonService::errorResp('Oops! Email or password credentials are invalid.', 401);
    }

    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->only('email'), [
            'email' => 'required|email:filter,rfc,dns|min:1|max:255',
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        try {
            $user = User::whereEmail($request->email)->first();

            if (!empty($user->id)) {
                $token = Str::random(64);
                $this->user->where('id', $user->id)->update(['remember_token' => $token]);
                Mail::send('auth.forget-password-email', ['token' => $token, 'user' => $user], function ($message) use ($request) {
                    $message->to($request->email);
                    $message->subject('Reset Password');
                });



                // SendEmailNotification::dispatch('ResetPassword', $request['email'], $user);
                return CommonService::successResp('Reset password link sent successfully', '', 201);
            } else {
                return CommonService::errorResp('Email id is not registered.', 422);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function ResetPassword($token)
    {
        return view('auth.forget-password-link', ['token' => $token]);
    }

    public function ResetPasswordStore(Request $request)
    {
        $validator = $request->validate([
            'password' => 'required|min:8|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{6,}$/',
            'password_confirmation' => 'required|same:password'
        ], [
            'password.regex' => "The password must have at least one uppercase, lowercase, numeric and special character.",
        ]);

        $update = User::where('remember_token', $request->token)->first();

        if (!$update) {
            return Redirect::back()->withFail('Invalid token!');
        }

        $user = User::where('remember_token', $request->token)->update(['password' => Hash::make($request->password), 'remember_token' => null]);

        return Redirect::back()->withSuccess('Your password has been successfully changed!');
    }

    public function ResetPasswordStoreAPI(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|min:8|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{6,}$/',
            'confirm_password' => 'required|same:password'
        ], [
            'password.regex' => "The password must have at least one uppercase, lowercase, numeric and special character.",
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        $update = User::where('remember_token', $request->token)->first();

        if (!$update) {
            return CommonService::errorResp('Invalid token!', 400);
        }

        $user = User::where('remember_token', $request->token)->update(['password' => Hash::make($request->password), 'remember_token' => null]);
        return CommonService::successResp('Your password has been successfully changed!', [], 201);
    }

    public function profileUpdate(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make(
            $request->all(),
            [
                'full_name' => 'required|string|min:1|max:255',
                'email' => ['required', 'email', 'email:filter,rfc,dns', 'min:1', 'max:255', Rule::unique("users")->ignore($user->id)->whereNull('deleted_at')],
                'mobile_no' => 'required|string|min:10|max:15',
                'gender' => 'nullable',
                'address' => 'sometimes|required|string|max:255|min:1',
                'zipcode' => 'sometimes|required|numeric|digits_between:2,8',
                'profile_photo' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:10240',
                'w9_document' => 'nullable|mimes:jpg,jpeg,png,svg,webp,pdf,docx,doc',
                'birth_date' => 'sometimes|required|date_format:Y-m-d|before:-14 years',
                'bio' => 'nullable|min:1|max:200',
                'bank.*.bank_name' => 'sometimes',
                'bank.*.bank_account_no' => 'sometimes',
                'bank.*.bank_routing' => 'sometimes',
                'company' => 'sometimes|array',
                'company.name' => 'sometimes|required|string|min:1|max:255',
                'company.address' => 'sometimes|required|string|min:1|max:255',
                'company.zipcode' => 'sometimes|required|numeric|digits_between:2,8',
                'job_preference' => 'sometimes|required'
            ],
            [
                'mobile_no.min' => "The mobile no field must be at least 10 digits",
                'mobile_no.max' => "The mobile no field must not be greater than 15 digits",
                'birth_date.before' => 'The birth date field must be a date before 14 years.',
                'company.name.required' => 'The company name field is required',
                'company.address.required' => 'The company address field is required',
                'company.zipcode.required' => 'The company zipcode field is required',
                'company.zipcode.digits_between' => 'The company zipcode field must be between 2 to 8 digits maximum'
            ]
        );

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        DB::beginTransaction();
        try {
            $profile_photo = $user->profile_photo;
            $w9_document = $user->w9_document;

            if (strtoupper($user->role) === 'A') {
                if ($request->file('profile_photo')) {
                    $profile_photo = Storage::disk('spaces')->put(config('app.image_path'), $request->file('profile_photo'), 'private');
                }
                $user->update($request->only('full_name', 'email', 'mobile_no'), [
                    'profile_photo' => $profile_photo
                ]);
            } else {

                if ($request->file('profile_photo')) {
                    $profile_photo = Storage::disk('spaces')->put(config('app.image_path'), $request->file('profile_photo'), 'private');
                }

                if ($request->file('w9_document')) {
                    $w9_document = Storage::disk('spaces')->put(config('app.document_path'), $request->file('w9_document'), 'private');
                }

                $jobPreference = null;
                if (strtoupper($user->role) === 'C') {
                    if (!empty($data['job_preference'])) {
                        $jobPreference = implode(',', $data['job_preference']);
                    }
                }

                $user->update($request->only('full_name', 'email', 'mobile_no', 'gender', 'address', 'zipcode', 'birth_date', 'bio') + [
                    'profile_photo' => $profile_photo,
                    'w9_document' => $w9_document,
                    'bank_name' => $data['bank'][0]['bank_name'],
                    'bank_account_no' => $data['bank'][0]['bank_account_no'],
                    'bank_routing' => $data['bank'][0]['bank_routing'],
                    'job_preference' => $jobPreference
                ]);

                if (strtoupper($user->role) === 'M') {
                    Company::where('user_id', '=', $user->id)->update(['name' => $data['company']['name'], 'address' => $data['company']['address'], 'zipcode' => $data['company']['zipcode']]);
                }
            }
            DB::commit();
            return CommonService::successResp('Profile updated successfully', new UserResource($user), 201);
        } catch (Exception $e) {
            echo ($e->getMessage());
            DB::rollBack();
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            if (!empty($request->device_id)) {
                Device::where([['device_id', $request->device_id], ['user_id', auth()->user()->id]])->delete();
            }
            auth()->user()->currentAccessToken()->delete();
            return CommonService::successResp('User logout successfully', '', 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getAllJobPreferences(Request $request)
    {
        try {
            $jobs = JobPreference::where([['status', '=', 'A']])->get();
            return CommonService::successResp('All job preferences data get successfully ..',  JobPreferenceResource::collection($jobs), 201);
        } catch (Exception $e) {
            https: //api.showtimestaff.sytepoint.com/api
            echo ($e->getMessage());
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required|min:8|regex:/^(?=.*?[A-Z])(?=.*?[a-z])(?=.*?[0-9])(?=.*?[#?!@$%^&*-]).{6,}$/',
            'confirm_password' => 'required|same:new_password'
        ], [
            'new_password.regex' => "The password must have at least one uppercase, lowercase, numeric and special character.",
        ]);

        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();
        try {
            if ((Hash::check($data['old_password'], auth()->user()->password)) == false) {
                return CommonService::errorResp('Please enter correct current password', 400);
            } else if ((Hash::check($data['new_password'], auth()->user()->password)) == true) {
                return CommonService::errorResp('Please enter a password which is not similar then current password', 400);
            } else {
                User::where('id', auth()->user()->id)->update(['password' => Hash::make($data['new_password'])]);
                return CommonService::successResp('Password updated successfully', [], 201);
            }
        } catch (\Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function notificationSetting(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email_notification' => 'required|boolean',
            'push_notification' => 'required|boolean',
            'sms_notification' => 'required|boolean',
        ]);


        if ($validator->fails()) {
            return CommonService::errorResp($validator->errors()->first(), 422);
        }

        $data = $validator->getData();

        try {
            User::where('id', auth()->user()->id)->update($request->only('email_notification', 'sms_notification', 'push_notification'));
            return CommonService::successResp('Notification setting updated successfully', new UserResource(auth()->user()), 201);
        } catch (\Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getUserByEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|email:filter,rfc,dns|min:1|max:255',
            ]);

            if ($validator->fails()) {
                return CommonService::errorResp($validator->errors()->first(), 422);
            }

            $data = $validator->getData();

            $user = User::where('email', $data['email'])->first();
            if ($user) {
                return CommonService::errorResp('This email address is already registered. Please try a different one', 400);
            } else {
                return CommonService::successResp('email is available', [], 201);
            }
        } catch (\Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function getUserById(string $id)
    {
        Log::info($id);
        try {
            $user = User::findOrFail($id);

            if (empty($user)) {
                return CommonService::errorResp('Oops! Email or password credentials are invalid.', 401);
            }

            if (!empty($user->status) && $user->status === 'D' || empty($user)) {
                return CommonService::errorResp('This account is decline. Please contact administration', 401);
            }

            $user->rating =  Review::where('user_id', $user->id)->pluck('rating')->avg();
            $user->user_reviews = Review::where('user_id', $user->id)->get();
            return CommonService::successResp('User data get successfully', new UserResource($user), 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function notificationList(Request $request)
    {
        try {
            $notificationData = Notification::where('user_id', auth()->user()->id)->orderBy('id', 'desc')->get();
            return CommonService::successResp('Notification data get successfully', NotificationResource::collection($notificationData), 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function notificationRead(Request $request, string $id)
    {
        try {
            $notificationData = Notification::where('id', $id)->update(['read_at' => Carbon::now()]);
            return CommonService::successResp('Notification read successfully', [], 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }

    public function notificationDelete(Request $request, string $id)
    {
        try {
            $notification = Notification::findOrFail($id);
            Notification::where('id', $id)->delete();
            return CommonService::successResp('notification data successfully deleted', [], 201);
        } catch (Exception $e) {
            return CommonService::errorResp('Something went wrong!', 500);
        }
    }
}
