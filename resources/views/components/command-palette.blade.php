{{-- pro-only (§6.7): the ⌘K palette has no free variant. The sidebar always includes this file; its whole content sits in the Pro branch. --}}
@if (Skv::hasFluxPro())
    <flux:modal.trigger name="command-palette" shortcut="cmd.k">
        <flux:sidebar.search :placeholder="__('Search...')" kbd="⌘K" data-test="command-palette" />
    </flux:modal.trigger>

    <flux:modal name="command-palette" variant="bare" class="my-[12vh] max-h-screen w-full max-w-[30rem] overflow-y-hidden">
        <flux:command class="inline-flex max-h-[76vh] flex-col border-none shadow-lg">
            <flux:command.input :placeholder="__('Go to…')" closable />
            <flux:command.items>
                @foreach (\App\Support\Navigation\Navigation::for(auth()->user()) as $section)
                    @foreach ($section['items'] as $item)
                        <flux:command.item :icon="$item->icon" x-on:click="Livewire.navigate({{ \Illuminate\Support\Js::from($item->url()) }})">
                            {{ $item->label() }}
                        </flux:command.item>
                    @endforeach
                @endforeach
            </flux:command.items>
        </flux:command>
    </flux:modal>
@endif
