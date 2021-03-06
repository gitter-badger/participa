<?php

namespace MXAbierto\Participa\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Model\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Zizaco\Entrust\Traits\EntrustUserTrait;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract
{
    use Authenticatable, CanResetPassword;
    use EntrustUserTrait;
    use SoftDeletes;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['password', 'token', 'last_login', 'updated_at', 'deleted_at', 'oauth_vendor', 'oauth_id', 'oauth_update'];

    /**
     * Validation rules.
     *
     * @var string[]
     */
    protected static $rules = [
        'save' => [
            'fname'    => 'required',
            'lname'    => 'required',
        ],
        'create' => [
            'email'            => 'required|unique:users',
            'password'         => 'required',
        ],
        'social-signup'    => [
            'email'               => 'required|unique:users',
            'oauth_vendor'        => 'required',
            'oauth_id'            => 'required',
            'oauth_update'        => 'required',
        ],
        'twitter-signup'    => [
            'oauth_vendor'        => 'required',
            'oauth_id'            => 'required',
            'oauth_update'        => 'required',
        ],
        'update'    => [
            'email'            => 'required|unique:users',
            'password'         => 'required',
        ],
        'verify'    => [
            'phone'            => 'required',
        ],
    ];

    /**
     * Custom error messages for certain validation requirements.
     *
     * @var string[]
     */
    protected static $customMessages = [
        'fname.required' => 'El campo de nombre es obligatorio.',
        'lname.required' => 'El campo de apellido es obligatorio.',
        'phone.required' => 'El campo de número de teléfono es requerido para solicitar la verificación de estado.',
    ];

    /**
     * Creates a new User instance.
     *
     * @param array $attributes
     *
     * @return void
     */
    public function __construct($attributes = [])
    {
        parent::__construct($attributes);
        $this->validationErrors = new MessageBag();
    }

    /**
     *	Save.
     *
     *	Override Model save() method
     *		Runs $this->beforeSave()
     *		Unsets:
     *			* $this->validationErrors
     *			* $this->rules
     *
     * @param array $options
     *
     * @return bool
     */
    public function save(array $options = [])
    {
        if (!$this->beforeSave()) {
            return false;
        }

        //Don't want user model trying to save validationErrors field.
        //	TODO: I'm sure Model can handle this.  What's the setting for ignoring fields when saving?
        unset($this->validationErrors);
        unset($this->rules);
        unset($this->verify);

        return parent::save($options);
    }

    /**
     *	getErrors.
     *
     *	Returns errors from validation
     *
     *	@param void
     *
     * @return MessageBag $this->validationErrors
     */
    public function getErrors()
    {
        return $this->validationErrors;
    }

    /**
     *	verified.
     *
     *	Returns the value of the UserMeta for this user with key 'verify'
     *		The value of this is either 'verified' or 'pending'
     *		If the user hasn't requested verified status, this will return null
     *
     *	@param void
     *
     * @return string||null
     */
    public function verified()
    {
        $request = $this->user_meta()->where('meta_key', 'verify')->first();

        if (isset($request)) {
            return $request->meta_value;
        } else {
            return;
        }
    }

    /**
     * Returns the user's display name.
     *
     * @param void
     *
     * @return string
     */
    public function getDisplayName()
    {
        return "{$this->fname} {$this->lname}";
    }

    /**
     * Gets the docs relation.
     *
     *
     * @return Illuminate\Database\Model\Relations\BelongsToMany
     */
    public function docs()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\Doc');
    }

    /**
     * Returns current active group for this user
     * Grabs the active group id from Session.
     *
     *
     * @return Group|| new Group
     *
     * @todo Why would this return a new group?  Should probalby return some falsy value.
     */
    public function activeGroup()
    {
        $activeGroupId = Session::get('activeGroupId');

        if ($activeGroupId <= 0) {
            return new Group();
        }

        return Group::where('id', '=', $activeGroupId)->first();
    }

    /**
     * Sets the password encrypted for a user.
     *
     * @param string $password
     *
     * @return void
     */
    public function setPasswordAttribute($password)
    {
        $this->attributes['password'] = bcrypt($password);
    }

    /**
     * Model belongsToMany relationship for Group.
     *
     * @return Illuminate\Database\Model\Relations\BelongsToMany
     */
    public function groups()
    {
        return $this->belongsToMany('MXAbierto\Participa\Models\Group', 'group_members');
    }

    /**
     * Model hasMany relationship for Comment.
     *
     * @return Illuminate\Database\Model\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Comment');
    }

    /**
     * Annotations relation.
     *
     * @return Illuminate\Database\Model\Relations\HasMany
     */
    public function annotations()
    {
        return $this->hasMany('MXAbierto\Participa\Models\Annotation');
    }

    /**
     * Model belongsTo relationship for Organization.
     *
     * @return Illuminate\Database\Model\Relations\BelongsTo
     *
     * @todo This can be removed as we use Groups in place of Organizations
     */
    public function organization()
    {
        return $this->belongsTo('MXAbierto\Participa\Models\Organization');
    }

    /**
     * Model hasMany relationship for NoteMeta.
     *
     * @return Illuminate\Database\Model\Relations\HasMany
     */
    public function note_meta()
    {
        return $this->hasMany('MXAbierto\Participa\Models\NoteMeta');
    }

    /**
     * Model hasMany relationship for UserMeta.
     *
     * @return Illuminate\Database\Model\Relations\HasMany
     */
    public function user_meta()
    {
        return $this->hasMany('MXAbierto\Participa\Models\UserMeta');
    }

    /**
     * Returns the value of the UserMeta for this user with key 'independent_sponsor'
     * The value of this is either '1' or '0'
     * If the user hasn't requested independent sponsor status, this will return null.
     *
     * @return string|null
     */
    public function getSponsorStatus()
    {
        return $this->user_meta()->where('meta_key', '=', UserMeta::TYPE_INDEPENDENT_SPONSOR)->first();
    }

    /**
     * Sets the Independent Sponsor status for this user
     * Sets / Creates a UserMeta for this user with key = 'independent_sponsor'
     * and value '1'||'0' based on input boolean.
     *
     * @param bool $bool
     *
     * @return void
     */
    public function setIndependentAuthor($bool)
    {
        if ($bool) {
            DB::transaction(function () {
                $metaKey = UserMeta::where('user_id', '=', $this->id)
                                   ->where('meta_key', '=', UserMeta::TYPE_INDEPENDENT_SPONSOR)
                                   ->first();

                if (!$metaKey) {
                    $metakey = new UserMeta();
                    $metaKey->user_id = $this->id;
                    $metaKey->meta_key = 'independent_author';
                }

                $metaKey->meta_value = $bool ? 1 : 0;
                $metaKey->save();
            });
        }
    }

    /**
     * Sets the user as an admin contact for the site.
     *
     * @param mixed $setting
     *
     * @return bool|void
     *
     * @todo References to this should be removed.  We're allowing all admins to determine notification subscriptions
     */
    public function admin_contact($setting = null)
    {
        if (isset($setting)) {
            $meta = $this->user_meta()->where('meta_key', '=', 'admin_contact')->first();

            if (!isset($meta)) {
                $meta = new UserMeta();
                $meta->user_id = $this->id;
                $meta->meta_key = 'admin_contact';
                $meta->meta_value = $setting;
                $meta->save();

                return true;
            } else {
                $meta->meta_value = $setting;
                $meta->save();

                return true;
            }
        }

        if (!$this->hasRole('Admin')) {
            return false;
        }

        $meta = $this->user_meta()->where('meta_key', '=', 'admin_contact')->first();

        if (isset($meta)) {
            $this->admin_contact = $meta->meta_value == '1' ? true : false;
        } else {
            $this->admin_contact = false;
        }
    }

    /**
     *	doc_meta.
     *
     *	Model hasMany relationship for DocMeta
     *
     *	@param void
     *
     *	@return Illuminate\Database\Model\Relations\HasMany
     */
    public function doc_meta()
    {
        return $this->hasMany('MXAbierto\Participa\Models\DocMeta');
    }

    /**
     *	Get all valid sponsors.
     *
     *	@todo I'm not sure what exactly this does at first glance
     */
    public function getValidSponsors()
    {
        $collection = new Collection();

        $groups = GroupMember::where('user_id', '=', $this->id)
                             ->whereIn('role', [Group::ROLE_EDITOR, Group::ROLE_OWNER])
                             ->get();

        foreach ($groups as $groupMember) {
            $collection->add($groupMember->group()->first());
        }

        $users = UserMeta::where('user_id', '=', $this->id)
                          ->where('meta_key', '=', UserMeta::TYPE_INDEPENDENT_SPONSOR)
                          ->where('meta_value', '=', '1')
                          ->get();

        foreach ($users as $userMeta) {
            $collection->add($userMeta->user()->first());
        }

        return $collection;
    }

    /**
     * Returns all users with a given role.
     *
     * @param string $role
     *
     * @return Illuminate\Database\Model\Collection
     */
    public static function findByRoleName($role)
    {
        return Role::where('name', '=', $role)->first()->users()->get();
    }

    /**
     * Validates before saving.  Returns whether the User can be saved.
     *
     * @param array $options
     *
     * @return bool
     */
    private function beforeSave(array $options = [])
    {
        $this->rules = $this->mergeRules();

        if (!$this->validate()) {
            Log::error('Unable to validate user: ');
            Log::error($this->getErrors()->toArray());
            Log::error($this->attributes);

            return false;
        }

        return true;
    }

    /**
     * Merge the rules arrays to form one set of rules.
     *
     * @return string[] $output
     *
     * @todo handle social login / signup rule merges
     */
    public function mergeRules()
    {
        $rules = static::$rules;
        $output = [];

        //If we're updating the user
        if ($this->exists) {
            $merged = array_merge_recursive($rules['save'], $rules['update']);
            $merged['email'] = 'required|unique:users,email,'.$this->id;
        }
        //If we're signing up via Oauth
        elseif (isset($this->oauth_vendor)) {
            switch ($this->oauth_vendor) {
                case 'twitter':
                    $merged = array_merge_recursive($rules['save'], $rules['twitter-signup']);
                    break;
                case 'facebook':
                case 'linkedin':
                    $merged = array_merge_recursive($rules['save'], $rules['social-signup']);
                    break;
                default:
                    throw new Exception('Unknown OAuth vendor: '.$this->oauth_vendor);
            }
        }
        //If we're creating a user via Madison
        else {
            $merged = array_merge_recursive($rules['save'], $rules['create']);
        }

        //Include verify rules if requesting verification
        if (isset($this->verify)) {
            $merged = array_merge_recursive($merged, $rules['verify']);
        }

        foreach ($merged as $field => $rules) {
            if (is_array($rules)) {
                $output[$field] = implode('|', $rules);
            } else {
                $output[$field] = $rules;
            }
        }

        return $output;
    }

    /**
     * Validate input against merged rules.
     *
     * @param array $attributes
     *
     * @return bool
     */
    public function validate()
    {
        $validation = Validator::make($this->attributes, $this->rules, static::$customMessages);

        if ($validation->passes()) {
            return true;
        }

        $this->validationErrors = $validation->messages();

        return false;
    }
}
