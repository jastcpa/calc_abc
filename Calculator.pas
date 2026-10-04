// Простой калькулятор (§5.6 "Создание приложения «Калькулятор»")
// PascalABC.NET + Windows Forms
uses System, System.Windows.Forms, System.Drawing;

//описание глобальных переменных
var
  n1, n2: integer;
  znak: char;

  Form1: Form;
  TextBox1: TextBox;
  button1, button2, button3, button4, button5, button6, button7, button8, button9, button10: Button;
  button11, button12, button13, button14, button15, button16: Button;

// ---------- цифровые кнопки ----------
// Для каждой цифры дописываем её к числу в поле TextBox1

procedure button1_Click(sender: Object; e: EventArgs);
begin
  //приписывание цифры к числу
  TextBox1.Text := TextBox1.Text + '1';
end;

procedure button2_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '2';
end;

procedure button3_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '3';
end;

procedure button4_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '4';
end;

procedure button5_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '5';
end;

procedure button6_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '6';
end;

procedure button7_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '7';
end;

procedure button8_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '8';
end;

procedure button9_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '9';
end;

procedure button10_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Text := TextBox1.Text + '0';
end;

// ---------- кнопки арифметических действий ----------
// Запоминаем первое число и знак операции, очищаем поле для второго числа

procedure button11_Click(sender: Object; e: EventArgs);
begin
  n1 := StrToInt(TextBox1.Text);
  //запоминание знака операции
  znak := '+';
  TextBox1.Clear;
end;

procedure button12_Click(sender: Object; e: EventArgs);
begin
  n1 := StrToInt(TextBox1.Text);
  znak := '-';
  TextBox1.Clear;
end;

procedure button13_Click(sender: Object; e: EventArgs);
begin
  n1 := StrToInt(TextBox1.Text);
  znak := '*';
  TextBox1.Clear;
end;

procedure button14_Click(sender: Object; e: EventArgs);
begin
  n1 := StrToInt(TextBox1.Text);
  znak := '/';
  TextBox1.Clear;
end;

// ---------- кнопка «C» (clear — очистить) ----------
procedure button15_Click(sender: Object; e: EventArgs);
begin
  TextBox1.Clear;
  n1 := 0; n2 := 0;
  znak := ' ';
end;

// ---------- кнопка «=» ----------
procedure button16_Click(sender: Object; e: EventArgs);
begin
  n2 := StrToInt(TextBox1.Text);
  case znak of
    '+': TextBox1.Text := IntToStr(n1 + n2);
    '-': TextBox1.Text := IntToStr(n1 - n2);
    '*': TextBox1.Text := IntToStr(n1 * n2);
    '/': if n2 = 0 then
           TextBox1.Text := 'Деление на 0'
         else
           TextBox1.Text := IntToStr(n1 div n2);
  end;
  n1 := 0; n2 := 0;
  znak := ' ';
end;

// ---------- создание кнопки на форме ----------
function NewButton(tekst: string; x, y, w, h: integer): Button;
begin
  Result := new Button;
  Result.Text := tekst;
  Result.Left := x;
  Result.Top := y;
  Result.Width := w;
  Result.Height := h;
  Form1.Controls.Add(Result);
end;

// ---------- главная программа: конструирование формы ----------
begin
  n1 := 0; n2 := 0;
  znak := ' ';

  Form1 := new Form;
  Form1.Text := 'Калькулятор';
  Form1.ClientSize := new Size(230, 295);
  //граница формы не позволяет менять размеры
  Form1.FormBorderStyle := FormBorderStyle.FixedSingle;
  Form1.MaximizeBox := false;
  Form1.StartPosition := FormStartPosition.CenterScreen;

  // поле для ввода/вывода чисел
  TextBox1 := new TextBox;
  TextBox1.Left := 10;
  TextBox1.Top := 10;
  TextBox1.Width := 210;
  TextBox1.TextAlign := HorizontalAlignment.Right;
  Form1.Controls.Add(TextBox1);

  // цифры
  button1 := NewButton('1', 10, 50, 40, 40);
  button2 := NewButton('2', 60, 50, 40, 40);
  button3 := NewButton('3', 110, 50, 40, 40);
  button4 := NewButton('4', 10, 100, 40, 40);
  button5 := NewButton('5', 60, 100, 40, 40);
  button6 := NewButton('6', 110, 100, 40, 40);
  button7 := NewButton('7', 10, 150, 40, 40);
  button8 := NewButton('8', 60, 150, 40, 40);
  button9 := NewButton('9', 110, 150, 40, 40);
  button10 := NewButton('0', 10, 200, 90, 40);

  // арифметические действия
  button11 := NewButton('+', 170, 100, 40, 40);
  button12 := NewButton('-', 170, 150, 40, 40);
  button13 := NewButton('*', 170, 200, 40, 40);
  button14 := NewButton('/', 120, 200, 40, 40);

  // «C» и «=»
  button15 := NewButton('C', 170, 50, 40, 40);
  button16 := NewButton('=', 10, 250, 200, 30);

  // назначение обработчиков события Click
  button1.Click += button1_Click;
  button2.Click += button2_Click;
  button3.Click += button3_Click;
  button4.Click += button4_Click;
  button5.Click += button5_Click;
  button6.Click += button6_Click;
  button7.Click += button7_Click;
  button8.Click += button8_Click;
  button9.Click += button9_Click;
  button10.Click += button10_Click;
  button11.Click += button11_Click;
  button12.Click += button12_Click;
  button13.Click += button13_Click;
  button14.Click += button14_Click;
  button15.Click += button15_Click;
  button16.Click += button16_Click;

  Application.Run(Form1);
end.
