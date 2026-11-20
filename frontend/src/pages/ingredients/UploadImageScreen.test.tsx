import { beforeEach, describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { UploadImageScreen } from './UploadImageScreen'

function renderUpload() {
  render(
    <MemoryRouter initialEntries={['/ingredients/upload']}>
      <Routes>
        <Route element={<UploadImageScreen />} path="/ingredients/upload" />
        <Route
          element={<p>Recognition destination</p>}
          path="/ingredients/recognition"
        />
      </Routes>
    </MemoryRouter>,
  )
}

function imageFile(name: string, type: string, bytes = 8) {
  return new File([new Uint8Array(bytes)], name, { type })
}

describe('UploadImageScreen', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
  })

  it('shows an empty recent images section when no session data exists', () => {
    renderUpload()

    expect(
      screen.getByText('No recent images in this session.'),
    ).toBeInTheDocument()
  })

  it('selects, previews, replaces and continues with a valid image', async () => {
    const user = userEvent.setup()
    renderUpload()
    const input = screen.getByLabelText('Choose an ingredient image')

    await user.upload(input, imageFile('ingredients.png', 'image/png'))
    expect(
      await screen.findByAltText('Selected ingredient image preview'),
    ).toBeInTheDocument()
    expect(
      (await screen.findAllByText('ingredients.png')).length,
    ).toBeGreaterThan(0)
    expect(screen.getByText('8 B')).toBeInTheDocument()

    await user.upload(input, imageFile('replacement.jpg', 'image/jpeg'))
    expect(
      (await screen.findAllByText('replacement.jpg')).length,
    ).toBeGreaterThan(0)
    expect(
      screen.getByAltText('Selected ingredient image preview'),
    ).toHaveAttribute('src', expect.stringContaining('data:image/jpeg'))

    await user.click(
      screen.getByRole('button', { name: 'Continue with Image' }),
    )
    expect(screen.getByText('Recognition destination')).toBeInTheDocument()
  })

  it('rejects unsupported file types and oversized images', async () => {
    renderUpload()
    const input = screen.getByLabelText('Choose an ingredient image')

    fireEvent.change(input, {
      target: { files: [imageFile('animation.gif', 'image/gif')] },
    })
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Only JPG and PNG images are supported.',
    )

    fireEvent.change(input, {
      target: {
        files: [imageFile('huge.png', 'image/png', 5 * 1024 * 1024 + 1)],
      },
    })
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Choose an image smaller than 5 MB.',
    )
  })

  it('handles cancelled selection and removing a preview', async () => {
    const user = userEvent.setup()
    renderUpload()
    const input = screen.getByLabelText('Choose an ingredient image')

    fireEvent.change(input, { target: { files: [] } })
    expect(
      await screen.findByText('Image selection was cancelled.'),
    ).toBeInTheDocument()

    await user.upload(input, imageFile('ingredients.png', 'image/png'))
    await screen.findByAltText('Selected ingredient image preview')
    await user.click(
      screen.getByRole('button', { name: 'Remove selected image' }),
    )

    expect(
      screen.queryByAltText('Selected ingredient image preview'),
    ).not.toBeInTheDocument()
    expect(
      screen.getByText('Image removed. Choose another image to continue.'),
    ).toBeInTheDocument()
  })

  it('shows real images selected earlier in this session', async () => {
    const user = userEvent.setup()
    renderUpload()

    await user.upload(
      screen.getByLabelText('Choose an ingredient image'),
      imageFile('session-image.png', 'image/png'),
    )
    await screen.findByAltText('Selected ingredient image preview')

    expect(screen.getByAltText('session-image.png')).toBeInTheDocument()
  })
})
