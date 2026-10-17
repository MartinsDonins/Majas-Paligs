@props(['step', 'total' => 4])
<div class="progress-dots my-4" role="img" aria-label="{{ __('onboarding.step_of', ['step' => $step, 'total' => $total]) }}">
    @for ($i = 1; $i <= $total; $i++)
        <span @if($i === $step) data-active @endif></span>
    @endfor
</div>
