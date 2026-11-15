{{--
    How a cashier gets the owner's approval on a return or void form.
    Needs: $autoApprove, $submit (button text for the counter choice), $submitRemote (button text for the phone choice)
--}}
@if ($autoApprove)
    {{-- The owner is asleep or away and turned on auto-approve --}}
    <input type="hidden" name="approval_via" value="auto">
    <div class="rounded-lg border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="status">
        <span class="font-semibold">Auto-approve is on</span> (the owner is away). This is approved and saved right away,
        and the owner sees it in their approvals history.
    </div>
@else
    <fieldset data-approval-choice>
        <legend class="label">Owner's approval</legend>
        <div class="mt-1 grid gap-2 sm:grid-cols-2">
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                <input type="radio" name="approval_via" value="here" class="mt-1" @checked(old('approval_via', 'here') === 'here')>
                <span class="text-sm"><span class="font-medium">The owner is here</span><br>
                    <span class="text-slate-500">They type their password or passkey below.</span></span>
            </label>
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                <input type="radio" name="approval_via" value="remote" class="mt-1" @checked(old('approval_via') === 'remote')>
                <span class="text-sm"><span class="font-medium">Send to the owner's phone</span><br>
                    <span class="text-slate-500">They approve from their own login, wherever they are. This screen waits for the answer.</span></span>
            </label>
        </div>
        <div class="mt-3 max-w-sm" data-approval-credential>
            <label class="label" for="approval-credential">Owner's password or passkey</label>
            {{-- Hidden typing without a real password box, so the cashier's browser never offers to save the owner's password --}}
            <input id="approval-credential" name="credential" type="text" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false"
                   style="-webkit-text-security: disc" class="input">
        </div>
    </fieldset>
    <script>
        (() => {
            const box = document.currentScript.previousElementSibling;
            // The submit button comes later in the page, so wait until it's there
            document.addEventListener('DOMContentLoaded', () => {
                const form = box.closest('form');
                const button = form.querySelector('[data-approval-submit]');
                const field = box.querySelector('[data-approval-credential]');
                const sync = () => {
                    const remote = form.querySelector('[name=approval_via]:checked')?.value === 'remote';
                    field.hidden = remote;
                    field.querySelector('input').required = !remote;
                    if (button) button.textContent = remote ? @json($submitRemote) : @json($submit);
                };
                box.addEventListener('change', sync);
                sync();
            });
        })();
    </script>
@endif
