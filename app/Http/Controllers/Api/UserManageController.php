<?php

namespace MXAbierto\Participa\Http\Controllers\Api;

/**
 * 	Controller for User Login/Signup API actions.
 */
class UserManageController extends AbstractApiController
{
    public function getLogin()
    {
        return View::make('login.api.index');
    }

    public function postLogin()
    {
        //Retrieve POST values
        $email = Input::get('email');
        $password = Input::get('password');
        $previous_page = Input::get('previous_page');
        $user_details = Input::all();

        //Rules for login form submission
        $rules = ['email' => 'required', 'password' => 'required'];
        $validation = Validator::make($user_details, $rules);

        //Validate input against rules
        if ($validation->fails()) {
            return Response::json(['status' => 'error', 'errors' => $validation->messages()->getMessages()]);
        }

        //Check that the user account exists
        $user = User::where('email', $email)->first();

        if (!isset($user)) {
            return Response::json(['status' => 'error', 'errors' => ['No such user']]);
        }

        //If the user's token field isn't blank, he/she hasn't confirmed their account via email
        if ($user->token != '') {
            return Response::json(['status'  => 'error',
                'errors'                     => ['Please click the link sent to your email to verify your account.'], ]);
        }

        //Attempt to log user in
        $credentials = ['email' => $email, 'password' => $password];

        if (Auth::attempt($credentials)) {
            return Response::json(['status' => 'ok', 'errors' => []]);
        } else {
            return Response::json(['status'  => 'error',
                'errors'                     => [Lang::get('messages.invalidcredentials')], ]);
        }
    }

    /**
     * 	GET Signup Page.
     */
    public function getSignup()
    {
        return View::make('login.api.signup');
    }

    /**
     * 	POST to create user account.
     */
    public function postSignup()
    {
        //Retrieve POST values
        $email = Input::get('email');
        $password = Input::get('password');
        $fname = Input::get('fname');
        $lname = Input::get('lname');
        $user_details = Input::all();

        //Rules for signup form submission
        $rules = ['email'              => 'required|unique:users',
                        'password'     => 'required',
                        'fname'        => 'required',
                        'lname'        => 'required',
                        ];
        $validation = Validator::make($user_details, $rules);
        if ($validation->fails()) {
            return Response::json(['status'  => 'error',
                'errors'                     => $validation->messages()->getMessages(), ]);
        } else {
            //Create user token for email verification
            $token = str_random();

            //Create new user
            $user = new User();
            $user->email = $email;
            $user->password = $password;
            $user->fname = $fname;
            $user->lname = $lname;
            $user->token = $token;
            $user->save();

            //Send email to user for email account verification
            Mail::queue('email.signup', ['token' => $token], function ($message) use ($email, $fname) {
          $message->subject(trans('messages.confirmationtitle'));
          $message->from(trans('messages.emailfrom'), trans('messages.emailfromname'));
                $message->to($email); // Recipient address
            });

            return Response::json(['status' => 'ok', 'errors' => [], 'message' => trans('messages.confirmationresent'),
      ]);
        }
    }
}
