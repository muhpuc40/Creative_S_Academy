<x-admin.layout title="Google Meet">
    <x-admin.page-header title="Google Meet"
        description="Connect the academy's Google account once. Then a live class gets its Google Meet link with one click: Generate, beside Meeting link in the class form." />

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            <x-admin.card title="Google Cloud client">
                <form method="POST" action="{{ route('admin.google-meet.client') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <x-admin.input name="client_id" label="Client ID" :value="$client?->client_id" placeholder="123456789-abc.apps.googleusercontent.com" required />
                    <x-admin.input name="client_secret" type="password" label="Client secret" autocomplete="off"
                        :placeholder="$client ? str($client->client_secret)->mask('*', 0, -4) : 'GOCSPX-...'" />
                    <div class="flex justify-end">
                        <x-admin.button type="submit">Save</x-admin.button>
                    </div>
                </form>
            </x-admin.card>

            <x-admin.card title="Connection">
                @if (! $client)
                    <p class="text-sm text-slate-500">Save the Client ID and secret first (see the steps).</p>
                @elseif (! $account)
                    <p class="text-sm text-slate-600">No Google account is connected. Sign in with the academy's Google account; the Meet links are made in its name.</p>
                    <x-admin.button :href="route('admin.google-meet.connect')" class="mt-4">
                        <x-admin.icon name="link" class="size-4" /> Connect Google
                    </x-admin.button>
                @else
                    <x-admin.info-list :items="[
                        'Account' => $account->email,
                        'Connected by' => $account->connectedBy?->name,
                        'Connected on' => $account->created_at->format('d M Y, h:i A'),
                    ]" />
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                        <x-admin.button :href="route('admin.google-meet.connect')" variant="secondary">Use another account</x-admin.button>
                        <form method="POST" action="{{ route('admin.google-meet.disconnect') }}"
                              onsubmit="return confirm('Disconnect this Google account? Existing class links keep working.')">
                            @csrf
                            @method('DELETE')
                            <x-admin.button type="submit" variant="danger">Disconnect</x-admin.button>
                        </form>
                    </div>
                @endif
            </x-admin.card>
        </div>

        <x-admin.card title="Set up in Google Cloud (once)">
            <ol class="list-decimal space-y-3 pl-5 text-sm text-slate-600">
                <li>Open <a href="https://console.cloud.google.com" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">console.cloud.google.com</a> and create (or pick) a project.</li>
                <li>APIs &amp; Services &gt; Library: enable <strong>Google Meet REST API</strong>.</li>
                <li>
                    Google Auth Platform (OAuth consent screen): give the app a name.
                    Audience <strong>Internal</strong> if the academy uses Google Workspace; otherwise <strong>External</strong>,
                    then <strong>Publish app</strong> (in "Testing" the access stops after 7 days).
                    In Data access add the scope <code>.../auth/meetings.space.created</code>.
                </li>
                <li>
                    Clients &gt; Create client &gt; <strong>Web application</strong>. Under Authorized redirect URIs add exactly:
                    <code class="mt-1.5 block select-all break-all rounded-md bg-slate-100 px-2.5 py-1.5 text-xs text-slate-800">{{ $redirectUri }}</code>
                </li>
                <li>Copy the Client ID and Client secret into <strong>Google Cloud client</strong> on this page and save.</li>
                <li>Click <strong>Connect Google</strong> and sign in with the academy's account. An External app that Google has not reviewed shows "Google hasn't verified this app": click Advanced, then continue (it is your own app).</li>
            </ol>
        </x-admin.card>
    </div>
</x-admin.layout>
