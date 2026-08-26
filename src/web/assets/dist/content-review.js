(function()
{
    function update_digest_weekday_visibility()
    {
        const digest_frequency_input = document.getElementById('digestFrequency')
        const digest_weekday_container = document.getElementById('digestWeekdayContainer')

        if (!digest_frequency_input || !digest_weekday_container)
        {
            return
        }

        const show_weekday_field = digest_frequency_input.value === 'weekly'

        digest_weekday_container.classList.toggle('hidden', !show_weekday_field)
        digest_weekday_container.setAttribute('aria-hidden', show_weekday_field ? 'false' : 'true')
    }

    function wire_all_checkbox_group(group_name)
    {
        const all_checkbox = document.querySelector(`input[name="settings[${group_name}][]"][value="all"]`)
        const other_checkboxes = Array.from(document.querySelectorAll(`input[name="settings[${group_name}][]"]`))
            .filter((checkbox) => checkbox.value !== 'all')

        if (!all_checkbox)
        {
            return
        }

        all_checkbox.addEventListener('change', () =>
        {
            if (all_checkbox.checked)
            {
                other_checkboxes.forEach((checkbox) =>
                {
                    checkbox.checked = false
                })
            }
        })

        other_checkboxes.forEach((checkbox) =>
        {
            checkbox.addEventListener('change', () =>
            {
                if (checkbox.checked)
                {
                    all_checkbox.checked = false
                }
                else if (other_checkboxes.every((other_checkbox) => !other_checkbox.checked))
                {
                    all_checkbox.checked = true
                }
            })
        })
    }

    function init_settings()
    {
        const digest_frequency_input = document.getElementById('digestFrequency')

        if (digest_frequency_input)
        {
            update_digest_weekday_visibility()
            digest_frequency_input.addEventListener('change', update_digest_weekday_visibility)
        }

        wire_all_checkbox_group('enabledSites')
        wire_all_checkbox_group('enabledSections')
    }

    function init_entry_pane()
    {
        const wrapper = document.getElementById('cr-wrapper')
        const enabled_toggle = document.getElementById('cr-enabled')

        if (wrapper && enabled_toggle)
        {
            const update_entry_pane_state = () =>
            {
                const enabled = enabled_toggle.getAttribute('aria-checked') === 'true'

                wrapper.classList.toggle('contentreview-disabledForEntry', !enabled)
                document.getElementById('cr-nextReviewDue')?.classList.toggle('hidden', !enabled)
                document.getElementById('cr-explicitReviewOn-field')?.toggleAttribute('inert', !enabled)
                document.getElementById('cr-reviewer-field')?.toggleAttribute('inert', !enabled)
            }

            document.getElementById('cr-enabled-label').addEventListener('click', (event) =>
            {
                event.preventDefault()
            })

            update_entry_pane_state()

            enabled_toggle.addEventListener('click', () =>
            {
                window.requestAnimationFrame(update_entry_pane_state)
            })
        }

        if (!document.querySelector('[data-content-review-mark-reviewed]'))
        {
            return
        }

        document.addEventListener('click', async (event) =>
        {
            const button = event.target.closest('[data-content-review-mark-reviewed]')

            if (!button)
            {
                return
            }

            button.disabled = true

            const form = button.closest('form')
            const element_editor = form ? window.jQuery(form).data('elementEditor') : null

            if (element_editor)
            {
                try
                {
                    await element_editor.checkForm()
                }
                catch (error)
                {
                    Craft.cp.displayError('Could not save pending entry changes.')
                    button.disabled = false
                    return
                }
            }

            const form_data = new FormData()
            form_data.append(window.Craft.csrfTokenName, window.Craft.csrfTokenValue)
            form_data.append('elementId', button.dataset.elementId)
            form_data.append('siteId', button.dataset.siteId)

            try
            {
                const response = await fetch(button.dataset.actionUrl, {
                    method: 'POST',
                    body: form_data,
                    headers: {
                        Accept: 'application/json',
                    },
                })

                const response_data = await response.json()

                if (!response.ok || !response_data.success)
                {
                    throw new Error(response_data.message || 'Could not mark entry as reviewed.')
                }

                Craft.cp.displayNotice(response_data.message || 'Entry marked as reviewed.')
                window.location.reload()
            }
            catch (error)
            {
                Craft.cp.displayError(error.message || 'Could not mark entry as reviewed.')
                button.disabled = false
            }
        })
    }

    init_settings()
    init_entry_pane()
})()
