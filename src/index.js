import validator from "validator";
import helper from "./helper";
import { checkID } from "./id-date-required";

const validatePassport = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isAlphanumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 9, max: 9 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateDriversLicense = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");

      const validate =
        validator.isAlphanumeric(trim_id.toString()) &&
        validator.isLength(trim_id.toString(), { min: 11, max: 11 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validatePRC = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 7, max: 7 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateTIN = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");

      const validate =
        validator.isNumeric(trim_id.toString()) &&
        validator.isLength(trim_id.toString(), { min: 12, max: 12 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateSSS = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");

      const validate =
        validator.isNumeric(trim_id.toString()) &&
        validator.isLength(trim_id.toString(), { min: 10, max: 10 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateGSIS = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 11, max: 11 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateOWWA = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 11, max: 11 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validatePostal = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/\s+/g, "");
      const validate =
        validator.isAlphanumeric(trim_id.toString()) &&
        validator.isLength(trim_id.toString(), { min: 16, max: 16 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateIBP = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 5, max: 5 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateOFW = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 11, max: 11 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateGovernment = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) ||
        validator.isAlphanumeric(idNumber.toString());

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateSeniorCitizen = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const validate =
        validator.isNumeric(idNumber.toString()) &&
        validator.isLength(idNumber.toString(), { min: 4, max: 4 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateCompany = (idNumber) => {
  try {
    if (helper.spaceonly(idNumber.toString())) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/[-\s]+/g, "");
      const validate =
        validator.isNumeric(trim_id.toString()) ||
        validator.isAlphanumeric(trim_id.toString());

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validateNationalID = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");
      const validate =
        validator.isNumeric(trim_id.toString()) ||
        validator.isLength(trim_id.toString(), { min: 16, max: 16 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validatePagibig = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");
      const validate =
        validator.isNumeric(trim_id.toString()) ||
        validator.isLength(trim_id.toString(), { min: 12, max: 12 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

const validatePhilhealth = (idNumber) => {
  try {
    if (
      helper.dashOnly(idNumber.toString()) ||
      helper.spaceonly(idNumber.toString())
    ) {
      return {
        isValid: false,
      };
    } else {
      const trim_id = idNumber.replace(/-/g, "");
      const validate =
        validator.isNumeric(trim_id.toString()) ||
        validator.isLength(trim_id.toString(), { min: 12, max: 12 });

      return {
        isValid: validate,
      };
    }
  } catch (error) {
    console.error({ error });
  }
};

export default {
  checkID,

  validatePassport,
  validateDriversLicense,
  validatePRC,
  validateTIN,
  validateSSS,
  validateGSIS,
  validateOWWA,
  validatePostal,
  validateIBP,
  validateOFW,
  validateGovernment,
  validateSeniorCitizen,
  validateCompany,
  validateNationalID,
  validatePagibig,
  validatePhilhealth,
};
