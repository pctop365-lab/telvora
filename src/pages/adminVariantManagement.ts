export type VariantMutationAction = 'add' | 'set_active' | 'price_manual' | 'price_automatic';

export function variantMutationErrorMessage(status: number, action: VariantMutationAction): string {
  const priceAction = action === 'price_manual' || action === 'price_automatic';
  if (status === 400) return action === 'add' ? 'Проверьте страну сборки.' : priceAction ? 'Проверьте введённую цену.' : 'Некорректные данные варианта.';
  if (status === 401) return 'Сессия администратора завершена. Войдите снова.';
  if (status === 403) return 'Запрос отклонён: обновите страницу и войдите снова.';
  if (status === 404) return action === 'add' ? 'Товар больше не существует.' : 'Вариант больше не существует.';
  if (status === 409 && priceAction) return 'Режим цены не изменён: данные варианта изменились или это последний готовый вариант активного товара. Список обновлён с сервера.';
  if (status === 409) return action === 'add'
    ? 'Вариант с такой страной уже существует либо данные товара изменились. Список обновлён с сервера.'
    : 'Изменение отклонено. Для активного товара нельзя отключить последний готовый вариант: сначала подготовьте другой вариант либо скройте товар.';
  return 'Не удалось изменить вариант. Серверное состояние будет загружено повторно.';
}

export function isVariantDraft(identityReady: boolean, hasPublishedPrice: boolean): boolean {
  return identityReady && !hasPublishedPrice;
}

export function shouldConfirmVariantDisable(currentActive: boolean, requestedActive: boolean): boolean {
  return currentActive && !requestedActive;
}

export function canStartVariantMutation(pending: boolean, selectedProductId: number | null, requestProductId: number): boolean {
  return !pending && selectedProductId === requestProductId;
}

export function isCurrentVariantMutation(requestSequence: number, currentSequence: number, aborted: boolean): boolean {
  return requestSequence === currentSequence && !aborted;
}
