@extends('layouts.app', ['title' => 'Backups'])

@section('actions')
    <form method="POST" action="{{ route('backups.store') }}" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Backing up…';">
        @csrf
        <button class="btn btn-primary">Back up now</button>
    </form>
@endsection

@section('content')
    <div class="card p-5 text-sm">
        <p>
            The whole database is backed up <b>every week</b> by itself, and the newest {{ \App\Services\DatabaseBackup::KEEP }} backups are kept.
            @if ($lastAt)
                Last backup: <b>{{ $lastAt->format('M j, Y g:i A') }}</b> ({{ $lastAt->diffForHumans() }}). Next one: around {{ $nextAt->format('M j, Y') }}.
            @else
                <b class="text-amber-700">No backup yet.</b> One is made the next time anyone uses the app, or press Back up now.
            @endif
        </p>
        <p class="mt-2 text-slate-500">
            Backups are saved on this computer only. If the computer breaks they are lost with it, so download one now and then and keep it on a USB stick or in Google Drive.
            They contain everything, including the accounts, so keep them private.
        </p>
    </div>

    <div class="card mt-4 overflow-x-auto">
        <table class="table table-stack">
            <thead><tr><th>Backup</th><th>Made</th><th class="text-right">Size</th><th><span class="sr-only">Download</span></th></tr></thead>
            <tbody>
            @forelse ($backups as $b)
                <tr>
                    <td class="font-mono text-xs">{{ $b['name'] }}</td>
                    <td>{{ $b['at']->format('M j, Y g:i A') }}</td>
                    <td class="text-right">{{ number_format($b['size'] / 1024, 0) }} KB</td>
                    <td class="text-right"><a href="{{ route('backups.download', $b['name']) }}" class="tap text-sm font-medium text-brand-700 hover:underline">Download</a></td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-8 text-center text-slate-400">No backups yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <details class="card mt-4 p-5 text-sm">
        <summary class="cursor-pointer font-semibold">How to restore a backup</summary>
        <ol class="mt-2 list-decimal space-y-1 pl-5 text-slate-600">
            <li>Download the backup you want.</li>
            <li>Open phpMyAdmin (XAMPP: <span class="font-mono">localhost/phpmyadmin</span>) and choose the database <span class="font-mono">{{ config('database.connections.'.config('database.default').'.database') }}</span>.</li>
            <li>Choose <b>Import</b>, pick the downloaded <span class="font-mono">.sql.gz</span> file and press <b>Import</b>. It replaces everything in the database with the backup.</li>
        </ol>
    </details>
@endsection
