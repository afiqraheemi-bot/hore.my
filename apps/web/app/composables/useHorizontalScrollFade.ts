/**
 * Drives a left/right fade hint over a horizontally-scrollable
 * element with more content than fits — e.g. the composer's 7
 * transaction-type tabs or the Work Queue's filter tabs, both of
 * which overflow a phone-width viewport with no native scrollbar to
 * hint at it. Without this, the extra tabs are reachable only by a
 * swipe nobody is prompted to try, reading as the page being "stuck"
 * rather than merely scrolled to its start.
 */
export function useHorizontalScrollFade() {
  const scrollRef = ref<HTMLElement | null>(null)
  const showLeftFade = ref(false)
  const showRightFade = ref(false)

  function update() {
    const el = scrollRef.value
    if (!el) return
    showLeftFade.value = el.scrollLeft > 4
    showRightFade.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
  }

  onMounted(() => {
    update()
    window.addEventListener('resize', update)
  })
  onUnmounted(() => {
    window.removeEventListener('resize', update)
  })

  return { scrollRef, showLeftFade, showRightFade, updateScrollFade: update }
}
