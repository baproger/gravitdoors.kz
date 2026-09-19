{{--
    Матрица доступа: строки — права, колонки — роли.

    Первая колонка закреплена: на семи ролях таблица шире экрана, и без
    закреплённого названия непонятно, какое право настраиваешь.
--}}
<x-filament-panels::page>
    @php
        $roles = $this->roles();
        $groups = $this->groups();
    @endphp

    <div class="am">
        <div class="am__scroll">
            <table class="am__table">
                <thead>
                    <tr>
                        <th class="am__corner">Право</th>
                        @foreach ($roles as $role)
                            <th class="am__role">
                                <span class="am__role-name">{{ $role->getLabel() }}</span>
                                @if ($role === \App\Enums\UserRole::Admin)
                                    <span class="am__role-note">всегда полный</span>
                                @elseif ($this->overrideCount($role) > 0)
                                    <span class="am__role-note am__role-note--changed">изменено: {{ $this->overrideCount($role) }}</span>
                                @else
                                    <span class="am__role-note">по ТЗ</span>
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    @foreach ($groups as $group => $permissions)
                        <tr class="am__group">
                            <th colspan="{{ count($roles) + 1 }}">{{ $group }}</th>
                        </tr>

                        @foreach ($permissions as $permission)
                            <tr wire:key="perm-{{ $permission->value }}">
                                <th class="am__permission">
                                    <span class="am__permission-name">{{ $permission->getLabel() }}</span>
                                    @if ($permission->hint())
                                        <span class="am__permission-hint">{{ $permission->hint() }}</span>
                                    @endif
                                </th>

                                @foreach ($roles as $role)
                                    @php($level = $this->levelOf($role, $permission))
                                    <td class="am__cell">
                                        @if ($role === \App\Enums\UserRole::Admin)
                                            <span class="am__fixed" title="Директор проходит любую проверку">Полный</span>
                                        @else
                                            <select
                                                class="am__select am__select--{{ $level->value }} {{ $this->isOverridden($role, $permission) ? 'am__select--changed' : '' }}"
                                                title="{{ $level->hint() }}"
                                                wire:change="setLevel(@js($role->value), @js($permission->value), $event.target.value)"
                                            >
                                                @foreach ($permission->levels() as $option)
                                                    <option value="{{ $option->value }}" @selected($option === $level)>{{ $option->getLabel() }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="am__legend">
            @foreach (\App\Enums\AccessLevel::cases() as $level)
                <span class="am__legend-item">
                    <i class="am__dot am__dot--{{ $level->value }}"></i>
                    <strong>{{ $level->getLabel() }}</strong> — {{ $level->hint() }}
                </span>
            @endforeach
            <span class="am__legend-item am__legend-item--muted">
                Жёлтая рамка — уровень отличается от рекомендованного в ТЗ.
                Сотрудник всегда видит свою зарплату и свою карточку, независимо от матрицы.
            </span>
        </div>
    </div>
</x-filament-panels::page>
