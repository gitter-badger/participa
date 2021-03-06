<?php

namespace MXAbierto\Participa\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use MXAbierto\Participa\Models\Group;
use MXAbierto\Participa\Models\GroupMember;
use MXAbierto\Participa\Models\Geography;

class GroupsController extends AbstractController
{
    /**
     * Creates a new groups controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth', ['only' => ['getIndex', 'getEdit', 'putEdit', 'setActiveGroup']]);
    }

    /**
     * Lists all user groups.
     *
     * @return \Illuminate\View\View
     */
    public function getIndex()
    {
        $userGroups = GroupMember::where('user_id', '=', Auth::user()->id)->get();

        return view('groups.index', [
            'groups' => $userGroups
        ]);
    }

    /**
     * Gets a group to edit.
     *
     * @param  int|null $groupId
     *
     * @return \Illuminate\View\View
     */
    public function getNew()
    {
        $group = new Group();
        $states = ['' => trans('messages.pleaseselect')] + Geography::getUSStates();

        return view('groups.new', [
            'states' => $states,
            'group'  => $group,
        ]);
    }

    /**
     * Creates a new group.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\Response
     */
    public function postNew(Request $request)
    {
        $this->validate($request, [
            'gname' => 'required',
        ]);

        $group = new Group();
        $group->status = Group::STATUS_PENDING;

        $message = '¡Tu grupo ha sido creado! Debe ser aprovado antes de que puedas invitar a otros a unirse o crear documentos.';

        $group->name = $group_details['gname'];
        $group->display_name = $group_details['dname'];
        $group->address1 = $group_details['address1'];
        $group->address2 = $group_details['address2'];
        $group->city = $group_details['city'];
        $group->state = $group_details['state'];
        $group->postal_code = $group_details['postal'];
        $group->phone_number = $group_details['phone'];

        $group->save();
        $group->addMember(Auth::user()->id, Group::ROLE_OWNER);

        if ($group->status == Group::STATUS_PENDING) {
            Event::fire(MadisonEvent::VERIFY_REQUEST_GROUP, $group);
        }

        return redirect()->route('groups')->with('success_message', $message);
    }

    /**
     * Gets a group to edit.
     *
     * @param  int $groupId
     *
     * @return \Illuminate\View\View
     */
    public function getEdit($groupId)
    {
        $group = Group::where('id', '=', $groupId)->first();

        if (! $group) {
            return redirect()->back()->with('error', 'Grupo No Encontrado');
        }

        if (! $group->isGroupOwner(Auth::user()->id)) {
            return redirect()->back()->with('error', 'No puedes editar el grupo, no eres el dueño');
        }

        return view('groups.edit', [
            'states' => ['' => trans('messages.pleaseselect')] + Geography::getUSStates(),
            'group'  => $group,
        ]);
    }

    /**
     * Updates a group.
     *
     * @param  \Illuminate\Http\Request  $request
     *
     * @return \Illuminate\Http\Response
     */
    public function putEdit($groupId = null, Request $request)
    {
        $this->validate($request, [
            'gname' => 'required',
        ]);

        $group_details = $request->all();

        $group = Group::find($groupId);

        if (!$group->isGroupOwner(Auth::user()->id)) {
            return redirect()->to('groups')->with('error', 'No puedes modificar un grupo que no te pertenece.');
        }

        $message = '¡Tu grupo ha sido actualizado!';

        $group->name = $group_details['gname'];
        $group->display_name = $group_details['dname'];
        $group->address1 = $group_details['address1'];
        $group->address2 = $group_details['address2'];
        $group->city = $group_details['city'];
        $group->state = $group_details['state'];
        $group->postal_code = $group_details['postal'];
        $group->phone_number = $group_details['phone'];

        $group->save();
        $group->addMember(Auth::user()->id, Group::ROLE_OWNER);

        if ($group->status == Group::STATUS_PENDING) {
            Event::fire(MadisonEvent::VERIFY_REQUEST_GROUP, $group);
        }

        return redirect()->route('groups')->with('success_message', $message);
    }

    public function processMemberInvite($groupId)
    {
        $group = Group::where('id', '=', $groupId)->first();

        if (!$group) {
            return redirect()->back()->with('error', 'ID de Grupo no válido');
        }

        if (!$group->isGroupOwner(Auth::user()->id)) {
            return redirect()->back()->with('error', 'No puedes agregar personas al grupo a menos que seas el dueño del grupo');
        }

        $email = Input::all()['email'];
        $role = Input::all()['role'];

        if (!Group::isValidRole($role)) {
            return redirect()->back()->with('error', 'Tipo de Rol no válido');
        }

        $user = User::where('email', '=', $email)->first();

        if (!$user) {
            return redirect()->back()->with('error', 'Usuario no válido');
        }

        $userExists = (bool) GroupMember::where('user_id', '=', $user->id)
                                    ->where('group_id', '=', $group->id)
                                    ->count();

        if ($userExists) {
            return redirect()->back()->with('error', '¡Este usuario ya es miembro de este grupo!');
        }

        $newMember = new GroupMember();
        $newMember->user_id = $user->id;
        $newMember->group_id = $group->id;
        $newMember->role = $role;

        $newMember->save();
        $text = 'Has sido agregado al grupo '.$group->getDisplayName().' con el rol de '.$role.'.';

        // Notify member of invite
        Mail::queue('email.notification', ['text' => $text], function ($message) use ($email) {
            $message->subject('Has sido agregado a un grupo de Madison');
            $message->from('sayhello@opengovfoundation.org', 'Madison');
            $message->to($email);
        });

        return redirect()->to('groups/members/'.(int) $group->id)
                        ->with('success_message', trans('messages.addednewmember'));
    }

    public function inviteMember($groupId)
    {
        $group = Group::where('id', '=', $groupId)->first();

        if (!$group) {
            return redirect()->back()->with('error', 'ID de Grupo no válido');
        }

        if (!$group->isGroupOwner(Auth::user()->id)) {
            return redirect()->back()->with('error', 'No puedes agregar personas a un grupo a menos que seas el dueño del grupo');
        }

        if ($group->status != Group::STATUS_ACTIVE) {
            return redirect()->to('groups')->with('error', 'No puedes agregar personas a un grupo no verificado');
        }

        return view('groups.invite.index', compact('group'));
    }

    public function getMembers($groupId)
    {
        $groupMembers = GroupMember::findByGroupId($groupId);
        $group = Group::where('id', '=', $groupId)->first();

        return view('groups.members.index', compact('groupMembers', 'group'));
    }

    public function removeMember($memberId)
    {
        $group = Group::findByMemberId($memberId);

        if (!$group) {
            return redirect()->to('groups')->with('error', 'No se pudo encontrar el grupo al que esta persona pertenece');
        }

        $members = GroupMember::where('group_id', '=', $group->id)->count();

        if ($members <= 1) {
            return redirect()->to('groups/members/'.(int) $group->id)->with('error', 'No puedes eliminar el último miembro del grupo');
        }

        $member = GroupMember::where('id', '=', $memberId);

        $member->delete();

        return redirect()->to('groups/members/'.(int) $group->id)->with('success_message', 'Miembro eliminado');
    }

    public function setActiveGroup($groupId)
    {
        try {

            if ($groupId == 0) {
                Session::remove('activeGroupId');

                return redirect()->back()->with('message', 'Grupo Activo eliminado');
            }

            if (!Group::isValidUserForGroup(Auth::user()->id, $groupId)) {
                return redirect()->back()->with('error', 'Grupo no válido');
            }

            Session::put('activeGroupId', $groupId);

            return redirect()->back()->with('message', 'Grupo Activo Cambiado');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Hubo un error al procesar tu petición');
        }
    }

    public function changeMemberRole($memberId)
    {
        $retval = [
            'success' => false,
            'message' => 'Error Desconocido',
        ];

        try {
            $groupMember = GroupMember::where('id', '=', $memberId)->first();

            if (!$groupMember) {
                $retval['message'] = 'No se pudo encontrar el miembro';

                return Response::json($retval);
            }

            $group = Group::where('id', '=', $groupMember->group_id)->first();

            if (!$group) {
                $retval['message'] = 'No se pudo encontrar el grupo';

                return Response::json($retval);
            }

            if (!$group->isGroupOwner(Auth::user()->id)) {
                $retval['message'] = '¡No eres el dueño del grupo!';

                return Response::json($retval);
            }

            $newRole = Input::all('role')['role'];

            if (!Group::isValidRole($newRole)) {
                $retval['message'] = "Rol no válido: $newRole";

                return Response::json($retval);
            }

            if ($newRole != Group::ROLE_OWNER) {
                $owners = GroupMember::where('group_id', '=', $groupMember->group_id)
                                     ->where('role', '=', Group::ROLE_OWNER)
                                     ->count();

                if ($owners <= 1) {
                    $retval['message'] = '¡El Grupo debe tener un dueño!';

                    return Response::json($retval);
                }
            }

            $groupMember->role = $newRole;
            $groupMember->save();

            $retval['success'] = true;
            $retval['message'] = 'Miembro Actualizado';

            return Response::json($retval);
        } catch (\Exception $e) {
            $retval['message'] = "Exception Caught: {$e->getMessage()}";

            return Response::json($retval);
        }

        return Response::json($retval);
    }
}
