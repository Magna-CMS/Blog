{{--
  Global, reusable colour picker.
  Params:
    $bind      Alpine expression for the current value (e.g. "button.color")
    $onpick    Alpine statement run on change, with `hex` in scope
               (e.g. "setButton('color', hex)")
    $clearable "true" | "false" — whether a "None" option is offered
  Values are always #rrggbb or '' (none). The server re-validates the hex.
--}}
<div class="de-colorpicker"
     x-data="magnaColorPicker({ value: {{ $bind }}, allowClear: {{ $clearable ?? 'true' }}, onPick: (hex) => { {!! $onpick !!} } })"
     x-effect="value = ({{ $bind }} || '')"
     @click.outside="open = false">
    <button type="button" class="de-colorpicker__trigger" @click="open = ! open">
        <span class="de-colorpicker__swatch" :style="`background:${display}`"></span>
        <span class="de-colorpicker__hex" x-text="value || 'None'"></span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </button>

    <div class="de-colorpicker__pop" x-show="open" x-cloak x-transition.opacity>
        <div class="de-colorpicker__grid">
            <template x-for="hex in swatches" :key="hex">
                <button type="button" class="de-colorpicker__cell"
                        :class="{ 'is-active': value && value.toLowerCase() === hex.toLowerCase() }"
                        :style="`background:${hex}`" :title="hex" @click="pick(hex)"></button>
            </template>
        </div>
        <div class="de-colorpicker__custom">
            <input type="color" :value="isValid(value) ? value : '#6366f1'"
                   @input="pick($event.target.value)" aria-label="Custom colour">
            <input type="text" class="de-input de-colorpicker__hexinput" placeholder="#rrggbb"
                   :value="value" @change="onHex($event)">
            <template x-if="allowClear">
                <button type="button" class="de-colorpicker__clear" @click="clear()">None</button>
            </template>
        </div>
    </div>
</div>
