import { useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { Button } from '../ui/Button'
import { Input, type InputProps } from '../ui/Input'

export type PasswordInputProps = Omit<InputProps, 'type' | 'endAdornment'>

export function PasswordInput(props: PasswordInputProps) {
  const [isVisible, setIsVisible] = useState(false)
  const label = isVisible ? 'Hide password' : 'Show password'

  return (
    <Input
      {...props}
      endAdornment={
        <Button
          aria-label={`${label} for ${props.label}`}
          className="h-8 w-8 rounded-full p-0"
          onClick={() => setIsVisible((visible) => !visible)}
          size="sm"
          variant="ghost"
        >
          {isVisible ? (
            <EyeOff aria-hidden="true" size={18} />
          ) : (
            <Eye aria-hidden="true" size={18} />
          )}
        </Button>
      }
      type={isVisible ? 'text' : 'password'}
    />
  )
}
