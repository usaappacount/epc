
@forelse($items as $item)
    <tr class="hover:bg-slate-50 transition">
        <td class="py-4 px-6 font-mono font-semibold text-slate-700">
            {{ $item->item_number }}
        </td>

        <td class="py-4 px-6 font-medium text-slate-900">
            {{ $item->item_name }}
        </td>

        <td class="py-4 px-6">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $item->quantity > 10 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                {{ number_format($item->quantity) }}
            </span>
        </td>

        <td class="py-4 px-6 font-semibold text-slate-800">
            {{ number_format($item->price, 2) }} ر.س
        </td>

        <td class="py-4 px-6">
            @if(auth()->user()->role === 'admin')
                <div class="flex items-center gap-3">
                    <button
                        type="button"
                        onclick="openEditModal({{ $item->id }}, {{ Js::from($item->item_number) }}, {{ Js::from($item->item_name) }}, {{ $item->quantity }}, {{ (float) $item->price }})"
                        class="text-blue-600 hover:text-blue-800 font-semibold">
                        تعديل
                    </button>

                    <form method="POST"
                          action="{{ route('inventory.destroy', $item) }}"
                          onsubmit="return confirm('هل أنت متأكد من حذف هذا الصنف؟')">
                        @csrf
                        @method('DELETE')

                        <button type="submit" class="text-rose-600 hover:text-rose-800 font-semibold">
                            حذف
                        </button>
                    </form>
                </div>
            @else
                <span class="text-slate-400">عرض فقط</span>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="py-8 px-6 text-center text-slate-500">
            لا توجد أصناف مطابقة للبحث.
        </td>
    </tr>
@endforelse
