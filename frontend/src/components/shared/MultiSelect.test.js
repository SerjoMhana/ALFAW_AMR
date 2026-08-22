import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import MultiSelect from './MultiSelect.vue'

/**
 * The folded-away list. What matters is that it reports itself honestly when
 * closed and hands back exactly what was ticked.
 */
describe('MultiSelect', () => {
  const options = [
    { value: 1, label: 'GRADE 1', hint: '2026-2027' },
    { value: 2, label: 'GRADE 2', hint: '2026-2027' },
    { value: 3, label: 'GRADE 3', hint: '2026-2027' },
  ]

  let wrapper

  function open(modelValue = [], extra = {}) {
    wrapper = mount(MultiSelect, {
      props: { modelValue, options, placeholder: 'اختر فصلاً أو أكثر', ...extra },
      attachTo: document.body,
    })

    return wrapper
  }

  beforeEach(() => {
    document.body.innerHTML = ''
  })

  it('stays closed until it is asked for', async () => {
    open()

    expect(wrapper.find('.multi-select-panel').exists()).toBe(false)

    await wrapper.find('.multi-select-toggle').trigger('click')

    expect(wrapper.find('.multi-select-panel').exists()).toBe(true)
    expect(wrapper.findAll('.multi-select-list .checkbox-label')).toHaveLength(3)
  })

  it('shows the placeholder when nothing is chosen', async () => {
    open()

    expect(wrapper.find('.multi-select-toggle').text()).toContain('اختر فصلاً أو أكثر')
  })

  it('names one or two choices, and counts more than that', async () => {
    open([1])
    expect(wrapper.find('.multi-select-toggle').text()).toContain('GRADE 1')

    await wrapper.setProps({ modelValue: [1, 2] })
    expect(wrapper.find('.multi-select-toggle').text()).toContain('GRADE 1، GRADE 2')

    await wrapper.setProps({ modelValue: [1, 2, 3] })
    // Three names would be a paragraph on a button.
    expect(wrapper.find('.multi-select-toggle').text()).toContain('3')
    expect(wrapper.find('.multi-select-toggle').text()).not.toContain('GRADE 2')
  })

  it('adds and removes a value without touching the rest', async () => {
    open([2])
    await wrapper.find('.multi-select-toggle').trigger('click')

    await wrapper.findAll('.multi-select-list input')[0].trigger('change')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([2, 1])

    await wrapper.findAll('.multi-select-list input')[1].trigger('change')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([])
  })

  it('selects and clears everything at once', async () => {
    open()
    await wrapper.find('.multi-select-toggle').trigger('click')

    await wrapper.findAll('.multi-select-tools button')[0].trigger('click')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([1, 2, 3])

    await wrapper.findAll('.multi-select-tools button')[1].trigger('click')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([])
  })

  it('offers no search box for a short list', async () => {
    open()
    await wrapper.find('.multi-select-toggle').trigger('click')

    expect(wrapper.find('input[type="search"]').exists()).toBe(false)
  })

  it('filters a long list as you type', async () => {
    open([], { searchFrom: 2 })
    await wrapper.find('.multi-select-toggle').trigger('click')

    await wrapper.find('input[type="search"]').setValue('grade 2')

    expect(wrapper.findAll('.multi-select-list .checkbox-label')).toHaveLength(1)
    expect(wrapper.text()).toContain('GRADE 2')
  })

  it('says so when the search matches nothing', async () => {
    open([], { searchFrom: 2 })
    await wrapper.find('.multi-select-toggle').trigger('click')
    await wrapper.find('input[type="search"]').setValue('zzz')

    expect(wrapper.findAll('.multi-select-list .checkbox-label')).toHaveLength(0)
    expect(wrapper.text()).toContain('لا توجد نتائج مطابقة.')
  })

  it('closes when the page is clicked elsewhere', async () => {
    open()
    await wrapper.find('.multi-select-toggle').trigger('click')
    expect(wrapper.find('.multi-select-panel').exists()).toBe(true)

    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.multi-select-panel').exists()).toBe(false)
  })

  it('closes on Escape', async () => {
    open()
    await wrapper.find('.multi-select-toggle').trigger('click')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await wrapper.vm.$nextTick()

    expect(wrapper.find('.multi-select-panel').exists()).toBe(false)
  })
})
