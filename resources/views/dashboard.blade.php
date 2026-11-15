@extends('layouts.app', ['title' => 'Dashboard', 'hideHeading' => true])

@section('content')
    {{-- Cashiers' voids and returns waiting for the owner, and the switch to turn them off --}}
    <div class="mb-4" data-react="ApprovalsPanel" data-props='@json(['initial' => \App\Http\Controllers\ApprovalController::panelData(), 'compact' => true])'></div>
    <div data-react="Dashboard" data-props='@json(['initial' => $data, 'userName' => auth()->user()->name])'></div>
@endsection
