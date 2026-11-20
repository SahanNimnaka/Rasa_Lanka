import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { Button, Input } from './index'

describe('shared UI components', () => {
  it('supports button interaction and disabled state', async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()

    render(
      <Button disabled onClick={onClick}>
        Continue
      </Button>,
    )

    const button = screen.getByRole('button', { name: 'Continue' })
    expect(button).toBeDisabled()

    await user.click(button)

    expect(onClick).not.toHaveBeenCalled()
  })

  it('associates input labels and validation errors accessibly', () => {
    render(<Input label="Ingredient name" error="Enter an ingredient name" />)

    const input = screen.getByRole('textbox', { name: 'Ingredient name' })
    expect(input).toHaveAttribute('aria-invalid', 'true')
    expect(input).toHaveAccessibleDescription('Enter an ingredient name')
  })
})
