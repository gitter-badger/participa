<!DOCTYPE html>
<html lang="en-US">
	<head>
		<meta charset="utf-8" />
	</head>
	<body>
		<h1>{{ trans('messages.confirmationtitle') }}</h1>

    <p>{{ trans('messages.confirmationaction') }}<a href="{{ URL::to('participa/user/verify/' . $token) }}">{{ trans('messages.confirmationlink') }}</a></p>

    {{ trans('messages.whatcanverifiedaccountsdo') }}

    {{ trans('messages.confirmationcontact') }}
	</body>
</html>
