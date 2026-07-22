const validatePhone = (phone) => {
  if (!phone) {
    return { valid: false, message: '请输入手机号' };
  }
  const reg = /^1[3-9]\d{9}$/;
  if (!reg.test(phone)) {
    return { valid: false, message: '请输入正确的手机号' };
  }
  return { valid: true, message: '' };
};

const validateIdCard = (idCard) => {
  if (!idCard) {
    return { valid: false, message: '请输入身份证号' };
  }

  const reg = /^[1-9]\d{5}(19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$/;
  if (!reg.test(idCard)) {
    return { valid: false, message: '请输入正确的身份证号' };
  }

  const weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
  const checkCodes = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];

  let sum = 0;
  for (let i = 0; i < 17; i++) {
    sum += parseInt(idCard[i]) * weights[i];
  }
  const checkCode = checkCodes[sum % 11];

  if (checkCode !== idCard[17].toUpperCase()) {
    return { valid: false, message: '身份证号校验不通过' };
  }

  return { valid: true, message: '' };
};

const validatePassword = (password) => {
  if (!password) {
    return { valid: false, message: '请输入密码' };
  }
  if (password.length < 6) {
    return { valid: false, message: '密码长度不能少于6位' };
  }
  if (password.length > 20) {
    return { valid: false, message: '密码长度不能超过20位' };
  }

  let strength = 0;
  let suggestions = [];

  if (/\d/.test(password)) strength++;
  if (/[a-zA-Z]/.test(password)) strength++;
  if (/[^a-zA-Z0-9]/.test(password)) strength++;

  if (strength === 1) {
    suggestions.push('建议包含字母、数字和特殊字符');
  } else if (strength === 2) {
    suggestions.push('建议增加特殊字符以提高安全性');
  }

  return {
    valid: true,
    message: '',
    strength: strength,
    level: strength === 1 ? '弱' : (strength === 2 ? '中' : '强'),
    suggestions: suggestions
  };
};

const validateAmount = (amount) => {
  if (!amount && amount !== 0) {
    return { valid: false, message: '请输入金额' };
  }
  const num = parseFloat(amount);
  if (isNaN(num)) {
    return { valid: false, message: '请输入有效的金额' };
  }
  if (num <= 0) {
    return { valid: false, message: '金额必须大于0' };
  }
  if (num > 99999.99) {
    return { valid: false, message: '金额不能超过99999.99' };
  }
  if (!/^\d+(\.\d{1,2})?$/.test(amount)) {
    return { valid: false, message: '金额最多保留两位小数' };
  }

  return { valid: true, message: '' };
};

const validateSmsCode = (code) => {
  if (!code) {
    return { valid: false, message: '请输入验证码' };
  }
  if (!/^\d{4,6}$/.test(code)) {
    return { valid: false, message: '请输入正确的验证码' };
  }
  return { valid: true, message: '' };
};

const validateInviteCode = (code) => {
  if (!code) {
    return { valid: false, message: '请输入邀请码' };
  }
  if (code.length < 6 || code.length > 10) {
    return { valid: false, message: '邀请码格式不正确' };
  }
  return { valid: true, message: '' };
};

const validateRealName = (name) => {
  if (!name) {
    return { valid: false, message: '请输入真实姓名' };
  }
  if (name.length < 2) {
    return { valid: false, message: '姓名至少2个字符' };
  }
  if (name.length > 20) {
    return { valid: false, message: '姓名不能超过20个字符' };
  }
  const reg = /^[\u4e00-\u9fa5a-zA-Z·]+$/;
  if (!reg.test(name)) {
    return { valid: false, message: '姓名只能包含中文、英文和·' };
  }
  return { valid: true, message: '' };
};

module.exports = {
  validatePhone,
  validateIdCard,
  validatePassword,
  validateAmount,
  validateSmsCode,
  validateInviteCode,
  validateRealName
};